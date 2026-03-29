<?php

// 1. Environment
$envPath = __DIR__ . '/../shared/.env';
if (file_exists($envPath)) {
    $_ENV = array_merge($_ENV, parse_ini_file($envPath, false, INI_SCANNER_TYPED));
}

function requireEnv(string $key): string
{
    if (!isset($_ENV[$key]) || $_ENV[$key] === '') {
        http_response_code(500);
        exit("Missing required environment variable: $key");
    }
    return $_ENV[$key];
}

// 2. Autoloader
require __DIR__ . '/../src/vendor/autoload.php';

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\NativeFileSessionHandler;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// 3. Session Setup
$request   = Request::createFromGlobals();
$basePath  = $request->getBasePath() ?: '/web';
$isSecure  = true;

$sessionOptions = [
    'cookie_path'     => $basePath,
    'cookie_domain'   => '.tekcent.com',
    'cookie_secure'   => $isSecure,
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'gc_maxlifetime'  => 86400,
];

$handler = new NativeFileSessionHandler(null);

session_set_save_handler($handler, true);

/*session_set_cookie_params([
    'lifetime' => 0,
    'path'     => $basePath,
    'domain'   => '.tekcent.com',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);*/

session_name('orangehrm');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Force cookie
//header('Set-Cookie: orangehrm=' . session_id() . '; Path=' . $basePath . '; Domain=.tekcent.com; Secure; HttpOnly; SameSite=Lax', false);

// 4. JWT Validator (unchanged)
class JwtValidator
{
    private string $jwksUrl;

    public function __construct(string $jwksUrl)
    {
        $this->jwksUrl = $jwksUrl;
    }

    public function getEmailFromJWT(string $jwt): string
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) throw new Exception("Malformed JWT");

        $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
        if (!isset($header['kid'])) throw new Exception("Missing 'kid'");

        $json = file_get_contents($this->jwksUrl);
        if (!$json) throw new Exception("Unable to fetch JWKS");

        $data = json_decode($json, true);
        foreach ($data['public_certs'] as $certInfo) {
            if ($certInfo['kid'] === $header['kid']) {
                $decoded = JWT::decode($jwt, new Key($certInfo['cert'], 'RS256'));
                return $decoded->email ?? throw new Exception("Email claim not found");
            }
        }
        throw new Exception("No certificate found for kid");
    }
};

// Main logic - JWT + DB (unchanged - keep your working part)
$jwt = $_SERVER['HTTP_CF_ACCESS_JWT_ASSERTION'] ?? null;

if (!$jwt) {
    http_response_code(401);
    header("Location: " . requireEnv('CF_LAUNCHER'));
    exit("Missing JWT");
}

try {
    $validator = new JwtValidator(requireEnv('CF_JWKS_URL'));
    $email = $validator->getEmailFromJWT($jwt);
} catch (Exception $e) {
    http_response_code(403);
    exit("Invalid JWT: " . $e->getMessage());
}

// Database lookup (keep as is)
$mysqli = new mysqli(
    requireEnv('OHRM_DB_HOST'),
    requireEnv('OHRM_DB_USER'),
    requireEnv('OHRM_DB_PASS'),
    requireEnv('OHRM_DB_NAME')
);

if ($mysqli->connect_errno) {
    http_response_code(500);
    exit("MySQL connection failed");
}

$query = "
    SELECT e.emp_number, e.emp_work_email, u.id, u.user_name, u.user_role_id, u.date_entered,u.date_modified 
    FROM hs_hr_employee e 
    JOIN ohrm_user u ON e.emp_number = u.emp_number 
    WHERE e.emp_work_email = ? 
    LIMIT 1
";

$stmt = $mysqli->prepare($query);
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

$row = $result->fetch_assoc();
$stmt->close();
mysqli_close($mysqli);

if (!$row) {
    http_response_code(403);
    exit("User not found");
}

// ====================== SESSION POPULATION ======================

// Define userType and isAdmin
$isAdmin = (strtolower($row['user_role_id'] ?? '') === '1');
$userType = $isAdmin ? 'Admin' : 'ESS';
$dateModified = $row['date_modified'] ?? null;
$dateEntered = $row['date_entered'] ?? null;
if ($dateModified) {
    $userLastModified = date(DateTimeInterface::ATOM, strtotime($dateModified));
} elseif ($dateEntered) {
    $userLastModified = date(DateTimeInterface::ATOM, strtotime($dateEntered));
} else {
    $userLastModified = '';
}

$storage = new NativeSessionStorage($sessionOptions, $handler);
$session = new Session($storage);

// Completely reset the _sf2_attributes bag
$session->remove('_sf2_attributes');

// Set top-level session attributes (match normal login)
$session->set('auth', 1);
$session->set('loggedIn', 1);
$session->set('login', 1);
$session->set('user', (int)$row['id']);
$session->set('username', $row['user_name']);
$session->set('userType', $userType);
$session->set('isAdmin', $isAdmin ? 1 : 0);
$session->set('user.user_last_modified', $userLastModified);
$session->set('security.last_username', $row['user_name']);
$session->set('user.user_id', (int)$row['id']);
$session->set('user.user_role_id', (int)$row['user_role_id']);
$session->set('user.user_role_name', $userType);
$session->set('user.user_employee_number', (int)$row['emp_number']);
$session->set('user.last_modified', $userLastModified);
$session->set('user.has_admin_access', $isAdmin ? 1 : 0);
$session->set('user.is_authenticated', 1);

// Nested _sf2_attributes array (match normal login)
$sf2Attributes = [
    'user.is_authenticated'     => 1,
    'user.has_admin_access'     => $isAdmin ? 1 : 0,
    'user.user_id'              => (int)$row['id'],
    'user.user_role_id'         => (int)$row['user_role_id'],
    'user.user_role_name'       => $userType,
    'user.user_employee_number' => (int)$row['emp_number'],
    '_security.last_username'   => $row['user_name'],
    'user.user_last_modified'   => $userLastModified,
];
$session->set('_sf2_attributes', $sf2Attributes);

$session->save();

header("Location: /web/dashboard/index", true, 302);
exit();