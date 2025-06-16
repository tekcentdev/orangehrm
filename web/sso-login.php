<?php
// --- Load .env securely ---
$_ENV = parse_ini_file(__DIR__ . '/../.env') ?: [];

// --- Secure env var helper ---
function requireEnv(string $key): string {
    if (!isset($_ENV[$key]) || $_ENV[$key] === '') {
        http_response_code(500);
        exit("Missing required environment variable: $key");
    }
    return $_ENV[$key];
}

// --- Setup session ---
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

// Enable debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Autoload Composer dependencies
require __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;

// --- JwtValidator class ---
class JwtValidator {
    private string $publicKey;

    public function __construct(string $pemPath) {
        if (!file_exists($pemPath)) {
            throw new Exception("Public key file not found");
        }
        $this->publicKey = file_get_contents($pemPath);
        if (!$this->publicKey) {
            throw new Exception("Unable to read PEM file");
        }
    }

    public function getEmailFromJWT(string $jwt): string {
        try {
            $decoded = JWT::decode($jwt, new Key($this->publicKey, 'RS256'));
            if (!isset($decoded->email)) {
                throw new Exception("Email claim not found in JWT");
            }
            return $decoded->email;
        } catch (ExpiredException $e) {
            throw new Exception("JWT expired");
        } catch (Exception $e) {
            throw new Exception("Invalid JWT: " . $e->getMessage());
        }
    }
}

// --- Extract and validate JWT ---
$jwt = $_SERVER['HTTP_CF_ACCESS_JWT_ASSERTION'] ?? null;
if (!$jwt) {
    http_response_code(401);
    header("Location: " . requireEnv('CF_LAUNCHER'));
    exit("Missing JWT");
}

try {
    $validator = new JwtValidator(__DIR__ . '/../lib/cloudflare.pem');
    $email = $validator->getEmailFromJWT($jwt);
} catch (Exception $e) {
    http_response_code(403);
    exit($e->getMessage());
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
    SELECT
        e.emp_number, e.emp_work_email, e.emp_firstname, e.emp_lastname,
        u.id, u.user_name, u.user_role_id
    FROM
        hs_hr_employee e
    JOIN
        ohrm_user u ON e.emp_number = u.emp_number
    WHERE
        e.emp_work_email = ?
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

$_SESSION['loggedIn']   = true;
$_SESSION['login']      = true;
$_SESSION['username']   = $row['user_name'];
$_SESSION['userRole']   = $row['user_role_id'];
$_SESSION['id']         = $row['id'];
$_SESSION['empNumber']  = $row['emp_number'];
$_SESSION['email']      = $row['emp_work_email'];
$_SESSION['fullName']   = $row['emp_firstname'] . ' ' . $row['emp_lastname'];

// --- Normalize role ---
$role = strtolower($row['user_role_id'] ?? 'ess');

switch ($role) {
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