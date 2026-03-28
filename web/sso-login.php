<?php

// --- Load environment variables from ../shared/.env ---
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

// --- Secure session setup ---
session_name(requireEnv('OHRM_SESSION_NAME'));
if (isset($_COOKIE[requireEnv('OHRM_SESSION_NAME')])) {
    session_id($_COOKIE[requireEnv('OHRM_SESSION_NAME')]);
}
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => requireEnv('COOKIE_DOMAIN'),
    'secure' => true,
    'httponly' => true,
    'samesite' => 'None'
]);

require __DIR__ . '/../src/vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// --- JWT Validator using JWKS ---
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
        if (count($parts) !== 3) {
            throw new Exception("Malformed JWT");
        }

        $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
        if (!isset($header['kid'])) {
            throw new Exception("Missing 'kid' in JWT header");
        }

        $cert = $this->getCertByKid($header['kid']);
        $decoded = JWT::decode($jwt, new Key($cert, 'RS256'));

        if (!isset($decoded->email)) {
            throw new Exception("Email claim not found in JWT");
        }

        return $decoded->email;
    }

    private function getCertByKid(string $kid): string
    {
        $json = file_get_contents($this->jwksUrl);
        if (!$json) {
            throw new Exception("Unable to fetch JWKS from Cloudflare");
        }

        $data = json_decode($json, true);
        foreach ($data['public_certs'] as $certInfo) {
            if ($certInfo['kid'] === $kid) {
                return $certInfo['cert'];
            }
        }

        throw new Exception("No certificate found for kid: $kid");
    }
}

// --- Extract and validate JWT ---
$jwt = $_SERVER['HTTP_CF_ACCESS_JWT_ASSERTION'] ?? null;

if (isset($_GET['debug']) && $_GET['debug'] === 'true') {
    header('Content-Type: text/plain');
    var_dump([
        'jwt' => $jwt,
        '_ENV' => $_ENV,
    ]);
    exit;
}

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

// --- Connect to MySQL ---
$mysqli = new mysqli(
    requireEnv('OHRM_DB_HOST'),
    requireEnv('OHRM_DB_USER'),
    requireEnv('OHRM_DB_PASS'),
    requireEnv('OHRM_DB_NAME')
);
if ($mysqli->connect_errno) {
    http_response_code(500);
    exit("MySQL connection failed: " . $mysqli->connect_error);
}

// --- Lookup user ---
$query = "
    SELECT e.emp_number, e.emp_work_email, e.emp_firstname, e.emp_lastname,
           u.id, u.user_name, u.user_role_id
    FROM hs_hr_employee e
    JOIN ohrm_user u ON e.emp_number = u.emp_number
    WHERE e.emp_work_email = ?
    LIMIT 1
";
$stmt = $mysqli->prepare($query);
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    http_response_code(403);
    exit("User not found");
}
$row = $result->fetch_assoc();
$stmt->close();
$mysqli->close();

// --- Start session ---
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// --- Store session info ---
$_SESSION = array_merge($_SESSION, [
    'loggedIn'   => true,
    'login'      => true,
    'username'   => $row['user_name'],
    'userRole'   => $row['user_role_id'],
    'id'         => $row['id'],
    'empNumber'  => $row['emp_number'],
    'email'      => $row['emp_work_email'],
    'fullName'   => $row['emp_firstname'] . ' ' . $row['emp_lastname'],
]);

// --- Set role info ---
switch (strtolower($row['user_role_id'] ?? 'ess')) {
    case '1':
        $_SESSION['userType'] = 'Admin';
        $_SESSION['isAdmin'] = true;
        break;
    case '2':
        $_SESSION['userType'] = 'Supervisor';
        $_SESSION['isAdmin'] = false;
        break;
    default:
        $_SESSION['userType'] = 'ESS';
        $_SESSION['isAdmin'] = false;
        break;
}

// --- Symfony session attributes ---
$_SESSION['_sf2_attributes'] = [
    'user.has_admin_access'      => $_SESSION['isAdmin'],
    'user.user_id'               => $_SESSION['id'],
    'user.user_role_id'          => $_SESSION['userRole'],
    'user.user_role_name'        => $_SESSION['userType'],
    'user.user_employee_number'  => $_SESSION['empNumber'],
    'user.is_authenticated'      => 1,
];

// --- Redirect to dashboard ---
header("Location: /web/dashboard/index");
