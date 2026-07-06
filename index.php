<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/app_config.php';
require_once __DIR__ . '/config/session_token_auth.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

function debug_log($message, $data = null) {
    $logFile = __DIR__ . '/debug.log';
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] " . $message;
    if ($data !== null) {
        $logEntry .= " - " . print_r($data, true);
    }
    $logEntry .= PHP_EOL;
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

debug_log("=== Script Started ===");

session_start();
$shop = isset($_GET['shop']) ? $_GET['shop'] : (isset($_SESSION['shop']) ? $_SESSION['shop'] : '');
debug_log("Initial shop from GET/SESSION", $shop);

$sessionToken = get_bearer_token_php53();
debug_log("Bearer token from header", $sessionToken ? substr($sessionToken, 0, 20) . '...' : 'none');

if ($sessionToken) {
    $validatedToken = validate_shopify_session_token_php53($sessionToken, $api_secret, $api_key);
    debug_log("Token validation result", $validatedToken);
    if (isset($validatedToken['success']) && $validatedToken['success']) {
        $shop = $validatedToken['shop'];
        debug_log("Shop from validated token", $shop);
    }
}

if (!$shop) {
    debug_log("ERROR: Shop missing");
    die("Shop missing");
}

$_SESSION['shop'] = $shop;
session_write_close();

try {
    $pdo = getDatabaseConnection();
    debug_log("Database connection successful");
} catch (Exception $e) {
    debug_log("Database connection failed", $e->getMessage());
    die("Database connection failed: " . $e->getMessage());
}

$table = 'shop';

function create_shop_table_if_not_exists($pdo, $table) {
    debug_log("Checking if table exists", $table);
    
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        if ($stmt->rowCount() > 0) {
            debug_log("Table already exists", $table);
            return true;
        }
    } catch (Exception $e) {
        debug_log("Error checking table existence", $e->getMessage());
    }
    
    debug_log("Creating table", $table);
    
    $createTableSQL = "
        CREATE TABLE IF NOT EXISTS `$table` (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            shop VARCHAR(255) NOT NULL UNIQUE,
            session_access_token TEXT NULL,
            session_access_token_expires_at DATETIME NULL,
            session_refresh_token TEXT NULL,
            session_refresh_token_expires_at DATETIME NULL,
            session_token_updated_at DATETIME NULL,
            installed_at DATETIME NOT NULL,
            install_date DATETIME NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            status VARCHAR(50) DEFAULT 'active',
            INDEX idx_shop (shop)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    
    try {
        $pdo->exec($createTableSQL);
        debug_log("Table created successfully", $table);
        return true;
    } catch (Exception $e) {
        debug_log("Table creation failed", $e->getMessage());
        die("Unable to create shop table: " . $e->getMessage());
    }
}

create_shop_table_if_not_exists($pdo, $table);

try {
    $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE 'status'");
    $statusExists = $stmt->fetch() ? true : false;
    debug_log("Status column exists?", $statusExists);
    
    if (!$statusExists) {
        debug_log("Adding status column");
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN status VARCHAR(50) DEFAULT 'active'");
        debug_log("Status column added successfully");
    }
} catch (Exception $e) {
    debug_log("Status column check/creation error", $e->getMessage());
}
try {
    debug_log("Checking if shop exists in database", $shop);
    $stmt = $pdo->prepare("SELECT * FROM $table WHERE shop = :shop");
    $stmt->execute(array(':shop' => $shop));
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    debug_log("Shop data from database", $data);
} catch (Exception $e) {
    debug_log("Database query error", $e->getMessage());
    die("Database query error: " . $e->getMessage());
}

if (!$data) {
    debug_log("Shop not found, attempting to insert");
    try {
        $columns = array('shop', 'install_date', 'updated_at');
        
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE 'installed_at'");
            if ($stmt->fetch()) {
                $columns[] = 'installed_at';
                debug_log("Adding installed_at to insert columns");
            }
        } catch (Exception $e) {
            debug_log("Error checking installed_at column", $e->getMessage());
        }
        
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE 'status'");
            if ($stmt->fetch()) {
                $columns[] = 'status';
                debug_log("Adding status to insert columns");
            }
        } catch (Exception $e) {
            debug_log("Error checking status column", $e->getMessage());
        }
        
        // Build insert query
        $insertColumns = implode(', ', $columns);
        $placeholders = ':' . implode(', :', $columns);
        $insertSql = "INSERT INTO $table ($insertColumns) VALUES ($placeholders)";
        
        debug_log("Insert SQL", $insertSql);
        
        $insertStmt = $pdo->prepare($insertSql);
        $params = array(
            ':shop' => $shop,
            ':install_date' => date('Y-m-d H:i:s'),
            ':updated_at' => date('Y-m-d H:i:s')
        );
        
        if (in_array('installed_at', $columns)) {
            $params[':installed_at'] = date('Y-m-d H:i:s');
        }
        
        if (in_array('status', $columns)) {
            $params[':status'] = 'active';
        }
        
        debug_log("Insert parameters", $params);
        
        $result = $insertStmt->execute($params);
        debug_log("Insert result", $result);
        
        if ($result) {
            $lastId = $pdo->lastInsertId();
            debug_log("Last insert ID", $lastId);
            debug_log("Rows affected", $insertStmt->rowCount());
        }
        
        // Verify insert worked
        $stmt = $pdo->prepare("SELECT * FROM $table WHERE shop = :shop");
        $stmt->execute(array(':shop' => $shop));
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        debug_log("Data after insert verification", $data);
        
        if (!$data) {
            throw new Exception("Shop record not found after insert");
        }
        
        debug_log("Shop inserted successfully", $shop);
        
    } catch (Exception $e) {
        debug_log("Insert error", array(
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'trace' => $e->getTraceAsString()
        ));
        die("Unable to bootstrap shop installation record. Error: " . $e->getMessage());
    }

    if (!$data) {
        debug_log("ERROR: Data still not found after insert");
        die("Unable to bootstrap shop installation record.");
    }
}

debug_log("Shop record verified", $data);
if (isset($_GET['bootstrap_token']) && $_GET['bootstrap_token'] == '1') {
    debug_log("Bootstrap token request received");
    header('Content-Type: application/json');
    try {
        $bootstrapToken = get_bearer_token_php53();
        debug_log("Bootstrap token from header", $bootstrapToken ? substr($bootstrapToken, 0, 20) . '...' : 'none');
        
        if (!$bootstrapToken && isset($_GET['id_token'])) {
            $bootstrapToken = $_GET['id_token'];
            debug_log("Bootstrap token from GET", substr($bootstrapToken, 0, 20) . '...');
        }
        if ($bootstrapToken) {
            $bootstrapToken = preg_replace('/\s+/', '', trim($bootstrapToken));
            debug_log("Bootstrap token cleaned", substr($bootstrapToken, 0, 20) . '...');
        }
        
        if (!$bootstrapToken) {
            debug_log("ERROR: No bootstrap token found");
            http_response_code(401);
            echo json_encode(array('success' => false, 'message' => 'Missing session token'));
            exit;
        }
        
        $validatedBootstrap = validate_shopify_session_token_php53($bootstrapToken, $api_secret, $api_key);
        debug_log("Bootstrap token validation", $validatedBootstrap);
        
        if (!isset($validatedBootstrap['success']) || !$validatedBootstrap['success']) {
            debug_log("ERROR: Bootstrap validation failed", $validatedBootstrap);
            http_response_code(401);
            echo json_encode(array(
                'success' => false, 
                'message' => isset($validatedBootstrap['error']) ? $validatedBootstrap['error'] : 'Invalid session token'
            ));
            exit;
        }

        $shop = $validatedBootstrap['shop'];
        debug_log("Shop from bootstrap validation", $shop);
        $_SESSION['shop'] = $shop;

        debug_log("Calling get_valid_shop_access_token_php53");
        $tokenState = get_valid_shop_access_token_php53($pdo, $table, $shop, $api_key, $api_secret, $bootstrapToken);
        debug_log("Token state result", $tokenState);
        
        if (!isset($tokenState['success']) || !$tokenState['success']) {
            debug_log("ERROR: Token state failed", $tokenState);
            http_response_code(500);
            echo json_encode(array(
                'success' => false,
                'message' => isset($tokenState['error']) ? $tokenState['error'] : 'Unable to bootstrap shop token',
                'shop' => $shop
            ));
            exit;
        }

        $response = array(
            'success' => true, 
            'source' => isset($tokenState['source']) ? $tokenState['source'] : 'bootstrap'
        );
        debug_log("Bootstrap success", $response);
        echo json_encode($response);
        
    } catch (Exception $e) {
        debug_log("Bootstrap exception", array(
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ));
        http_response_code(500);
        echo json_encode(array(
            'success' => false,
            'message' => 'Bootstrap exception',
            'error' => $e->getMessage()
        ));
    }
    exit;
}

debug_log("=== Script Ended Normally ===");
?>
<script>
    function bootstrapSessionTokens() {
        function requestBootstrap(sessionToken) {
            if (!sessionToken) {
                console.log('No session token available');
                return;
            }
            
            const url = '?bootstrap_token=1&shop=<?php echo urlencode($shop); ?>&id_token=' + encodeURIComponent(sessionToken);
            console.log('Bootstrap request URL:', url);
            
            fetch(url, {
                method: 'GET',
                headers: {
                    'Authorization': 'Bearer ' + sessionToken
                }
            })
            .then(response => {
                console.log('Bootstrap response status:', response.status);
                return response.json();
            })
            .then(data => {
                console.log('Bootstrap response data:', data);
            })
            .catch(error => {
                console.error('Bootstrap error:', error);
            });
        }

        if (window.shopify && typeof window.shopify.idToken === 'function') {
            console.log('Shopify idToken function found');
            window.shopify.idToken()
                .then((token) => {
                    console.log('Got idToken:', token ? token.substring(0, 20) + '...' : 'none');
                    requestBootstrap(token || '');
                })
                .catch((error) => {
                    console.error('Failed to get idToken:', error);
                });
        } else {
            console.warn('Shopify idToken function not available');
            const urlParams = new URLSearchParams(window.location.search);
            const tokenFromUrl = urlParams.get('id_token');
            if (tokenFromUrl) {
                console.log('Using token from URL');
                requestBootstrap(tokenFromUrl);
            }
        }
    }
    document.addEventListener('DOMContentLoaded', function() {
        console.log('DOM loaded, running bootstrap');
        bootstrapSessionTokens();
    });
</script>
</body>
</html>