<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';
require $config['sharedpath'].'/logger.php';
require $config['sharedpath'].'/govee_lib.php';
require $config['sharedpath'].'/hue_lib.php';

function validateApiKey($request, $handler) {
    global $config;
    
    // Get API key from header
    $apiKey = $request->getHeaderLine('X-API-Key');
    
    // Check if API key exists and is valid
    if (empty($apiKey) || !in_array($apiKey, $config['api_keys'])) {
        $response = new \Slim\Psr7\Response();
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'Invalid or missing API key'
        ]));
        
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(401);
    }
    
    return $handler->handle($request);
}

// Helper Functions
function sendErrorResponse($response, $error, $log = null) {
    if ($log) {
        $log->logErrorMsg($error->getMessage());
    }
    
    $payload = json_encode([
        'success' => false,
        'error' => $error->getMessage()
    ]);
    
    $response->getBody()->write($payload);
    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus(500);
}

function sendSuccessResponse($response, $data, $status = 200) {
    $payload = json_encode(array_merge(
        ['success' => true],
        $data
    ));
    
    $response->getBody()->write($payload);
    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus($status);
}

function measureExecutionTime($callback) {
    $start = microtime(true);
    $result = $callback();
    $duration = round((microtime(true) - $start) * 1000);
    
    return [
        'result' => $result,
        'duration' => $duration
    ];
}

function validateRequiredParams($params, $required) {
    $missing = array_filter($required, function($param) use ($params) {
        return !isset($params[$param]);
    });
    
    if (!empty($missing)) {
        throw new Exception('Missing required parameters: ' . implode(', ', $missing));
    }
    
    return true;
}

function getDatabaseConnection($config) {
    return new PDO(
        "mysql:host={$config['db_config']['host']};dbname={$config['db_config']['dbname']};charset=utf8mb4",
        $config['db_config']['user'],
        $config['db_config']['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

function hasDevicePendingCommand($pdo, $device) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM command_queue 
        WHERE device = ? 
        AND status IN ('xpending', 'xprocessing')
    ");
    $stmt->execute([$device]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return ($result['count'] > 0);
}

function getDevicesFromDatabase($pdo, $single_device = null, $room = null, $exclude_room = null) {
    global $log;
    $log->logInfoMsg("Getting devices from the database.");
    
    try {
        $baseQuery = "SELECT devices.*, 
                      -- Use preferred values for display if they exist
                      COALESCE(devices.preferredPowerState, devices.powerState) as displayPowerState,
                      COALESCE(devices.preferredBrightness, devices.brightness) as displayBrightness,
                      rooms.room_name,
                      device_groups.name as group_name,
                      device_groups.id as group_id 
                      FROM devices 
                      LEFT JOIN rooms ON devices.room = rooms.id
                      LEFT JOIN device_groups ON devices.deviceGroup = device_groups.id";
        
        if ($single_device) {
            $stmt = $pdo->prepare($baseQuery . " WHERE devices.device = ?");
            $stmt->execute([$single_device]);
        } else {
            $whereConditions = [];
            $params = [];
            
            $whereConditions[] = "(devices.deviceGroup IS NULL OR 
                                 devices.showInGroupOnly = 0 OR 
                                 devices.device IN (SELECT reference_device FROM device_groups))";
            
            if ($room !== null) {
                $whereConditions[] = "devices.room = ?";
                $params[] = $room;
            } elseif ($exclude_room !== null) {
                $whereConditions[] = "devices.room != ?";
                $params[] = $exclude_room;
            }
            
            $query = $baseQuery;
            if (!empty($whereConditions)) {
                $query .= " WHERE " . implode(" AND ", $whereConditions);
            }
            
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
        }
        
        $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Filter out devices with pending commands
        $filteredDevices = array_filter($devices, function($device) use ($pdo) {
            return !hasDevicePendingCommand($pdo, $device['device']);
        });
        
        // Modify each device to use the display values for UI
        foreach ($filteredDevices as &$device) {
            // Use the displayPowerState and displayBrightness for UI
            $device['powerState'] = $device['displayPowerState'];
            $device['brightness'] = $device['displayBrightness'];
            
            // Remove the display fields to avoid confusion
            unset($device['displayPowerState']);
            unset($device['displayBrightness']);
            
            if (!empty($device['device']) && !empty($device['deviceGroup'])) {
                $groupStmt = $pdo->prepare(
                    "SELECT devices.device, devices.device_name, 
                            COALESCE(devices.preferredPowerState, devices.powerState) as powerState,
                            COALESCE(devices.preferredBrightness, devices.brightness) as brightness,
                            devices.online, devices.brand
                     FROM devices 
                     WHERE devices.deviceGroup = ? AND devices.device != ?
                     ORDER BY devices.device_name"
                );
                $groupStmt->execute([$device['deviceGroup'], $device['device']]);
                
                // Filter group members with pending commands
                $groupMembers = $groupStmt->fetchAll(PDO::FETCH_ASSOC);
                $device['group_members'] = array_filter($groupMembers, function($member) use ($pdo) {
                    return !hasDevicePendingCommand($pdo, $member['device']);
                });
            }
        }
        
        return array_values($filteredDevices); // Re-index array after filtering
        
    } catch (PDOException $e) {
        $log->logErrorMsg("Database error: " . $e->getMessage());
        throw new Exception("Database error occurred");
    } catch (Exception $e) {
        $log->logErrorMsg("Error in getDevicesFromDatabase: " . $e->getMessage());
        throw $e;
    }
}

// Create Slim app and configure middleware
$app = AppFactory::create();
$app->setBasePath('/homeio/api');
$app->addRoutingMiddleware();
//$app->add('validateApiKey');
$app->addErrorMiddleware(true, true, true);

// Initialize logger
$log = new logger(basename(__FILE__, '.php')."_", __DIR__);

// Routes
$app->get('/check-x10-code', function (Request $request, Response $response) use ($config, $log) {
    try {
        validateRequiredParams($request->getQueryParams(), ['x10Code']);
        $x10Code = $request->getQueryParams()['x10Code'];
        $currentDevice = $request->getQueryParams()['currentDevice'] ?? null;
        
        $pdo = getDatabaseConnection($config);
        $stmt = $pdo->prepare("SELECT device, device_name FROM devices WHERE x10Code = ? AND device != ?");
        $stmt->execute([$x10Code, $currentDevice]);
        $existingDevice = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return sendSuccessResponse($response, [
            'isDuplicate' => (bool)$existingDevice,
            'deviceName' => $existingDevice ? $existingDevice['device_name'] : null,
            'device' => $existingDevice ? $existingDevice['device'] : null
        ]);
    } catch (Exception $e) {
        return sendErrorResponse($response, $e, $log);
    }
});

$app->post('/delete-device-group', function (Request $request, Response $response) use ($config, $log) {
    try {
        $data = json_decode($request->getBody()->getContents(), true);
        validateRequiredParams($data, ['groupId']);
        
        $pdo = getDatabaseConnection($config);
        $pdo->beginTransaction();
        
        try {
            $stmt = $pdo->prepare("UPDATE devices SET deviceGroup = NULL, showInGroupOnly = 0 WHERE deviceGroup = ?");
            $stmt->execute([$data['groupId']]);
            
            $stmt = $pdo->prepare("DELETE FROM device_groups WHERE id = ?");
            $stmt->execute([$data['groupId']]);
            
            $pdo->commit();
            return sendSuccessResponse($response, ['message' => 'Group deleted successfully']);
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    } catch (Exception $e) {
        return sendErrorResponse($response, $e, $log);
    }
});

$app->get('/available-groups', function (Request $request, Response $response) use ($config, $log) {
    try {
        validateRequiredParams($request->getQueryParams(), ['model']);
        $model = $request->getQueryParams()['model'];
        
        $pdo = getDatabaseConnection($config);
        $stmt = $pdo->prepare("SELECT id, name FROM device_groups WHERE model = ?");
        $stmt->execute([$model]);
        
        return sendSuccessResponse($response, ['groups' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        return sendErrorResponse($response, $e, $log);
    }
});

$app->get('/group-devices', function (Request $request, Response $response) use ($config, $log) {
    try {
        validateRequiredParams($request->getQueryParams(), ['groupId']);
        
        $pdo = getDatabaseConnection($config);
        $stmt = $pdo->prepare("SELECT device, device_name, powerState, online FROM devices WHERE deviceGroup = ?");
        $stmt->execute([$request->getQueryParams()['groupId']]);
        
        return sendSuccessResponse($response, ['devices' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        return sendErrorResponse($response, $e, $log);
    }
});

$app->get('/device-config', function (Request $request, Response $response) use ($config) {
    try {
        validateRequiredParams($request->getQueryParams(), ['device']);
        
        $pdo = getDatabaseConnection($config);
        $stmt = $pdo->prepare("SELECT room, low, medium, high, preferredColorTem, x10Code FROM devices WHERE device = ?");
        $stmt->execute([$request->getQueryParams()['device']]);
        
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$config) {
            throw new Exception('Device not found');
        }
        
        return sendSuccessResponse($response, $config);
    } catch (Exception $e) {
        return sendErrorResponse($response, $e);
    }
});

$app->get('/devices', function (Request $request, Response $response) use ($config, $log) {
    try {
        $timing = [];
        $result = measureExecutionTime(function() use ($request, $config, $log, &$timing) {
            $single_device = $request->getQueryParams()['device'] ?? null;
            $room = $request->getQueryParams()['room'] ?? null;
            $exclude_room = $request->getQueryParams()['exclude_room'] ?? null;
            $quick = isset($request->getQueryParams()['quick']) ? 
                ($request->getQueryParams()['quick'] === 'true') : false;

            $pdo = getDatabaseConnection($config);
            
            if (!$quick) {
                // Only update Govee on non-quick refreshes
                $govee_timing = measureExecutionTime(function() use ($config, $log) {
                    $log->logInfoMsg("Starting Govee devices update (quick = false)");
                    try {
                        $goveeApi = new GoveeAPI($config['govee_api_key'], $config['db_config']);
                        $goveeDevices = $goveeApi->getDevices();
                        
                        if ($goveeDevices['statusCode'] !== 200) {
                            throw new Exception('Failed to update Govee devices: HTTP ' . $goveeDevices['statusCode']);
                        }
                        
                        $goveeData = json_decode($goveeDevices['body'], true);
                        if (!isset($goveeData['data']) || !isset($goveeData['data']['devices'])) {
                            throw new Exception('Invalid response format from Govee API');
                        }
                        
                        foreach ($goveeData['data']['devices'] as $device) {
                            // First update device info
                            $goveeApi->updateDeviceDatabase($device);
                            
                            // Then get and update device state
                            $log->logInfoMsg("Fetching state for device: " . $device['device']);
                            $stateResponse = $goveeApi->getDeviceState($device);
                            
                            if ($stateResponse['statusCode'] !== 200) {
                                $log->logErrorMsg("Failed to get state for device " . $device['device'] . ": HTTP " . $stateResponse['statusCode']);
                                continue;
                            }
                            
                            $stateData = json_decode($stateResponse['body'], true);
                            if (!$stateData || !isset($stateData['data']) || !isset($stateData['data']['properties'])) {
                                $log->logErrorMsg("Invalid state data for device " . $device['device']);
                                continue;
                            }
                            
                            $log->logInfoMsg("Updating state for device: " . $device['device']);
                            $device_states = [$device['device'] => $stateData['data']['properties']];
                            $goveeApi->updateDeviceStateInDatabase($device, $device_states, $device);
                        }
                        
                        $log->logInfoMsg("Govee update completed successfully");
                    } catch (Exception $e) {
                        $log->logErrorMsg("Govee update error: " . $e->getMessage());
                        throw $e;
                    }
                });
                $timing['govee'] = ['duration' => $govee_timing['duration']];
                
                
                
            }

            // Always update Hue devices
            $hue_timing = measureExecutionTime(function() use ($config, $log) {
                $log->logInfoMsg("Starting Hue devices update");
                try {
                    $hueApi = new HueAPI($config['hue_bridge_ip'], $config['hue_api_key'], $config['db_config']);
        $hueResponse = $hueApi->getDevices();
        
        $log->logInfoMsg("Hue API Response Status Code: " . $hueResponse['statusCode']);
        
        if ($hueResponse['statusCode'] !== 200) {
            throw new Exception('Failed to get devices from Hue Bridge');
        }
        
        $devices = json_decode($hueResponse['body'], true);
        if (!$devices || !isset($devices['data'])) {
            throw new Exception('Failed to parse Hue Bridge response');
        }
        
        $log->logInfoMsg("Number of Hue devices found: " . count($devices['data']));
        
        foreach ($devices['data'] as $device) {
            $log->logInfoMsg("Processing Hue device: " . $device['id'] . " Brightness: " . ($device['dimming']['brightness'] ?? 'none'));
            $hueApi->updateDeviceDatabase($device);
        }
                    
                    $log->logInfoMsg("Hue update completed successfully");
                } catch (Exception $e) {
                    $log->logErrorMsg("Hue update error: " . $e->getMessage());
                }
            });
            $timing['hue'] = ['duration' => $hue_timing['duration']];

            // Get all devices from database
            $devices_timing = measureExecutionTime(function() use ($pdo, $single_device, $room, $exclude_room) {
                return getDevicesFromDatabase($pdo, $single_device, $room, $exclude_room);
            });
            
            $timing['devices'] = ['duration' => $devices_timing['duration']];
            $timing['database'] = ['duration' => $devices_timing['duration']];
            
            return [
                'devices' => $devices_timing['result'],
                'updated' => date('c'),
                'timing' => $timing,
                'quick' => $quick
            ];
        });
        
        return sendSuccessResponse($response, $result['result']);
    } catch (Exception $e) {
        return sendErrorResponse($response, $e, $log);
    }
});

$app->get('/rooms', function (Request $request, Response $response) use ($config) {
    try {
        $pdo = getDatabaseConnection($config);
        $stmt = $pdo->query("SELECT id, room_name, tab_order, icon FROM rooms ORDER BY tab_order");
        
        return sendSuccessResponse($response, ['rooms' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        return sendErrorResponse($response, $e);
    }
});

$app->post('/update-room', function (Request $request, Response $response) use ($config) {
    try {
        $data = json_decode($request->getBody()->getContents(), true);
        validateRequiredParams($data, ['id', 'room_name', 'icon', 'tab_order']);
        
        $pdo = getDatabaseConnection($config);
        
        $stmt = $pdo->prepare("
            UPDATE rooms 
            SET room_name = ?,
                icon = ?,
                tab_order = ?
            WHERE id = ?
        ");
        
        $stmt->execute([
            $data['room_name'],
            $data['icon'],
            $data['tab_order'],
            $data['id']
        ]);
        
        return sendSuccessResponse($response, ['message' => 'Room updated successfully']);
        
    } catch (Exception $e) {
        return sendErrorResponse($response, $e);
    }
});

$app->post('/add-room', function (Request $request, Response $response) use ($config) {
    try {
        $data = json_decode($request->getBody()->getContents(), true);
        validateRequiredParams($data, ['room_name', 'icon', 'tab_order']);
        
        $pdo = getDatabaseConnection($config);
        
        $stmt = $pdo->prepare("
            INSERT INTO rooms (room_name, icon, tab_order)
            VALUES (?, ?, ?)
        ");
        
        $stmt->execute([
            $data['room_name'],
            $data['icon'],
            $data['tab_order']
        ]);
        
        return sendSuccessResponse($response, [
            'message' => 'Room added successfully',
            'room_id' => $pdo->lastInsertId()
        ]);
        
    } catch (Exception $e) {
        return sendErrorResponse($response, $e);
    }
});

$app->delete('/delete-room', function (Request $request, Response $response) use ($config) {
    try {
        $data = json_decode($request->getBody()->getContents(), true);
        validateRequiredParams($data, ['id']);
        
        $pdo = getDatabaseConnection($config);
        
        // First check if room has any devices
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM devices WHERE room = ?");
        $stmt->execute([$data['id']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['count'] > 0) {
            throw new Exception('Cannot delete room with assigned devices');
        }
        
        $stmt = $pdo->prepare("DELETE FROM rooms WHERE id = ? AND id != 1");
        $stmt->execute([$data['id']]);
        
        if ($stmt->rowCount() === 0) {
            throw new Exception('Room not found or cannot be deleted');
        }
        
        return sendSuccessResponse($response, ['message' => 'Room deleted successfully']);
        
    } catch (Exception $e) {
        return sendErrorResponse($response, $e);
    }
});

$app->get('/room-temperature', function (Request $request, Response $response) use ($config) {
    try {
        validateRequiredParams($request->getQueryParams(), ['room']);
        
        $pdo = getDatabaseConnection($config);
        // Get all thermometers for the room, joining with thermometer_history for latest readings
        $stmt = $pdo->prepare("
            SELECT 
                t.mac,
                t.name,
                t.display_name,
                th.temperature as temp,
                th.humidity,
                th.timestamp as updated
            FROM thermometers t
            LEFT JOIN thermometer_history th ON t.mac = th.mac
            INNER JOIN (
                SELECT mac, MAX(timestamp) as max_timestamp
                FROM thermometer_history
                GROUP BY mac
            ) latest ON th.mac = latest.mac AND th.timestamp = latest.max_timestamp
            WHERE t.room = ?
            ORDER BY t.display_name, t.name
        ");
        $stmt->execute([$request->getQueryParams()['room']]);
        
        $thermometers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Instead of throwing an error, just return empty array
        return sendSuccessResponse($response, [
            'thermometers' => $thermometers
        ]);
        
    } catch (Exception $e) {
        return sendErrorResponse($response, $e);
    }
});

$app->post('/send-command', function (Request $request, Response $response) use ($config, $log) {
    try {
        $data = json_decode($request->getBody()->getContents(), true);
        validateRequiredParams($data, ['device', 'model', 'cmd', 'brand']);
        
        $pdo = getDatabaseConnection($config);
        $stmt = $pdo->prepare("
            INSERT INTO command_queue 
            (device, model, command, brand) 
            VALUES 
            (:device, :model, :command, :brand)
        ");

        $stmt->execute([
            'device' => $data['device'],
            'model' => $data['model'],
            'command' => json_encode($data['cmd']),
            'brand' => $data['brand']
        ]);

        return sendSuccessResponse($response, [
            'message' => 'Command queued successfully',
            'command_id' => $pdo->lastInsertId()
        ]);
    } catch (Exception $e) {
        return sendErrorResponse($response, $e, $log);
    }
});

$app->post('/update-device-config', function (Request $request, Response $response) use ($config) {
    try {
        $data = json_decode($request->getBody()->getContents(), true);
        validateRequiredParams($data, ['device']);
        
        $pdo = getDatabaseConnection($config);
        $x10Code = (!empty($data['x10Code'])) ? $data['x10Code'] : null;
        
        $stmt = $pdo->prepare("UPDATE devices SET room = ?, low = ?, medium = ?, high = ?, preferredColorTem = ?, x10Code = ? WHERE device = ?");
        $stmt->execute([
            $data['room'],
            $data['low'],
            $data['medium'],
            $data['high'],
            $data['preferredColorTem'],
            $x10Code,
            $data['device']
        ]);
        
        return sendSuccessResponse($response, ['message' => 'Device configuration updated successfully']);
    } catch (Exception $e) {
        return sendErrorResponse($response, $e);
    }
});

$app->post('/update-device-group', function (Request $request, Response $response) use ($config) {
    try {
        $data = json_decode($request->getBody()->getContents(), true);
        validateRequiredParams($data, ['device', 'action']);
        
        $pdo = getDatabaseConnection($config);
        
        if ($data['action'] === 'create') {
            validateRequiredParams($data, ['groupName', 'model']);
            
            $stmt = $pdo->prepare("INSERT INTO device_groups (name, model, reference_device) VALUES (?, ?, ?)");
            $stmt->execute([$data['groupName'], $data['model'], $data['device']]);
            $groupId = $pdo->lastInsertId();
            
            $stmt = $pdo->prepare("UPDATE devices SET deviceGroup = ?, showInGroupOnly = 0 WHERE device = ?");
            $stmt->execute([$groupId, $data['device']]);
            
        } else if ($data['action'] === 'join') {
            validateRequiredParams($data, ['groupId']);
            
            $stmt = $pdo->prepare("UPDATE devices SET deviceGroup = ?, showInGroupOnly = 1 WHERE device = ?");
            $stmt->execute([$data['groupId'], $data['device']]);
            
        } else if ($data['action'] === 'leave') {
            $stmt = $pdo->prepare("UPDATE devices SET deviceGroup = NULL, showInGroupOnly = 0 WHERE device = ?");
            $stmt->execute([$data['device']]);
        }
        
        return sendSuccessResponse($response, ['message' => 'Device group updated successfully']);
    } catch (Exception $e) {
        return sendErrorResponse($response, $e);
    }
});

$app->post('/update-device-state', function (Request $request, Response $response) use ($config, $log) {
    try {
        $data = json_decode($request->getBody()->getContents(), true);
        validateRequiredParams($data, ['device', 'command', 'value']);
        
        $pdo = getDatabaseConnection($config);
        
        // Get current device state
        $stmt = $pdo->prepare("SELECT powerState, brightness, preferredPowerState, preferredBrightness FROM devices WHERE device = ?");
        $stmt->execute([$data['device']]);
        $currentState = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $shouldQueueCommand = false;
        
        switch($data['command']) {
            case 'turn':
                // Update preferred power state
                $stmt = $pdo->prepare("UPDATE devices SET preferredPowerState = ? WHERE device = ?");
                $stmt->execute([$data['value'], $data['device']]);
                
                // Queue command only if current state doesn't match preferred state
                if ($currentState['powerState'] !== $data['value']) {
                    $shouldQueueCommand = true;
                }
                break;
                
            case 'brightness':
                $brightness = (int)$data['value'];
                // Update preferred brightness
                $stmt = $pdo->prepare("UPDATE devices SET preferredBrightness = ?, preferredPowerState = 'on' WHERE device = ?");
                $stmt->execute([$brightness, $data['device']]);
                
                // Queue command only if current brightness doesn't match preferred brightness
                if ($currentState['brightness'] !== $brightness) {
                    $shouldQueueCommand = true;
                }
                break;
            
            default:
                throw new Exception('Invalid command type');
        }
        
        // Queue command if needed
        if ($shouldQueueCommand) {
            $stmt = $pdo->prepare("
                INSERT INTO command_queue 
                (device, model, command, brand) 
                SELECT 
                    device,
                    model,
                    :command,
                    brand
                FROM devices
                WHERE device = :device
            ");
            
            $stmt->execute([
                'command' => json_encode([
                    'name' => $data['command'],
                    'value' => $data['value']
                ]),
                'device' => $data['device']
            ]);
        }
        
        return sendSuccessResponse($response, ['message' => 'Device state preferences updated successfully']);
    } catch (Exception $e) {
        return sendErrorResponse($response, $e, $log);
    }
});

$app->get('/update-govee-devices', function (Request $request, Response $response) use ($config, $log) {
    try {
        $timing = [];
        $result = measureExecutionTime(function() use ($config, $log, &$timing) {
            $goveeApi = new GoveeAPI($config['govee_api_key'], $config['db_config']);
            
            $api_timing = measureExecutionTime(function() use ($goveeApi) {
                $goveeResponse = $goveeApi->getDevices();
                if ($goveeResponse['statusCode'] !== 200) {
                    throw new Exception('Failed to get devices from Govee API');
                }
                
                $result = json_decode($goveeResponse['body'], true);
                if ($result['code'] !== 200) {
                    throw new Exception($result['message']);
                }
                
                return $result;
            });
            $timing['devices'] = ['duration' => $api_timing['duration']];
            
            $states_timing = measureExecutionTime(function() use ($goveeApi, $api_timing) {
                $govee_devices = $api_timing['result']['data']['devices'];
                $device_states = array();
                $updated_devices = array();
                
                foreach ($govee_devices as $device) {
                    $goveeApi->updateDeviceDatabase($device);
                    
                    $stateResponse = $goveeApi->getDeviceState($device);
                    if ($stateResponse['statusCode'] === 200) {
                        $state_result = json_decode($stateResponse['body'], true);
                        if ($state_result['code'] === 200) {
                            $device_states[$device['device']] = $state_result['data']['properties'];
                            $updated_devices[] = $goveeApi->updateDeviceStateInDatabase($device, $device_states, $device);
                        }
                    }
                }
                
                return $updated_devices;
            });
            
            $timing['states'] = ['duration' => $states_timing['duration']];
            
            return [
                'devices' => $states_timing['result'],
                'updated' => date('c'),
                'timing' => $timing
            ];
        });
        
        return sendSuccessResponse($response, $result['result']);
    } catch (Exception $e) {
        return sendErrorResponse($response, $e, $log);
    }
});

$app->get('/update-hue-devices', function (Request $request, Response $response) use ($config, $log) {
    try {
        $timing = [];
        $result = measureExecutionTime(function() use ($config, &$timing) {
            // Initialize HueAPI
            $hueApi = new HueAPI($config['hue_bridge_ip'], $config['hue_api_key'], $config['db_config']);
            
            $api_timing = measureExecutionTime(function() use ($hueApi) {
                $hueResponse = $hueApi->getDevices();
                if ($hueResponse['statusCode'] !== 200) {
                    throw new Exception('Failed to get devices from Hue Bridge');
                }
                
                $devices = json_decode($hueResponse['body'], true);
                if (!$devices || !isset($devices['data'])) {
                    throw new Exception('Failed to parse Hue Bridge response');
                }
                
                return $devices;
            });
            $timing['devices'] = ['duration' => $api_timing['duration']];
            
            $states_timing = measureExecutionTime(function() use ($hueApi, $api_timing) {
                $updated_devices = array();
                foreach ($api_timing['result']['data'] as $device) {
                    $updated_devices[] = $hueApi->updateDeviceDatabase($device);
                }
                return $updated_devices;
            });
            
            $timing['states'] = ['duration' => $states_timing['duration']];
            
            return [
                'devices' => $states_timing['result'],
                'updated' => date('c'),
                'timing' => $timing
            ];
        });
        
        return sendSuccessResponse($response, $result['result']);
    } catch (Exception $e) {
        return sendErrorResponse($response, $e, $log);
    }
});

$app->get('/thermometer-history', function (Request $request, Response $response) use ($config) {
    try {
        validateRequiredParams($request->getQueryParams(), ['mac']);
        $mac = $request->getQueryParams()['mac'];
        $hours = isset($request->getQueryParams()['hours']) ? (int)$request->getQueryParams()['hours'] : 24;

        $pdo = getDatabaseConnection($config);
        
        // First get the device name and room info - updated query without JOIN
        $stmt = $pdo->prepare("
            SELECT name, display_name, room
            FROM thermometers 
            WHERE mac = ?
        ");
        $stmt->execute([$mac]);
        $device = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Then get the history
        $stmt = $pdo->prepare("
            SELECT 
                temperature,
                humidity,
                battery,
                rssi,
                DATE_FORMAT(timestamp, '%Y-%m-%d %H:%i') as timestamp
            FROM thermometer_history 
            WHERE mac = ? 
            AND timestamp >= DATE_SUB(NOW(), INTERVAL ? HOUR)
            ORDER BY timestamp DESC
        ");
        $stmt->execute([$mac, $hours]);
        
        return sendSuccessResponse($response, [
            'history' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'device_name' => $device ? ($device['display_name'] ?: $device['name']) : 'Unknown Device'
        ]);
    } catch (Exception $e) {
        return sendErrorResponse($response, $e);
    }
});

$app->get('/all-thermometer-history', function (Request $request, Response $response) use ($config) {
    try {
        // Get hours parameter, default to 24 if not specified
        $hours = isset($request->getQueryParams()['hours']) ? (int)$request->getQueryParams()['hours'] : 24;
        
        // Validate hours parameter
        if (!in_array($hours, [24, 168, 720])) {
            throw new Exception('Invalid hours parameter');
        }

        $pdo = getDatabaseConnection($config);
        
        // Get history for all thermometers with device names
        $stmt = $pdo->prepare("
            SELECT 
                th.temperature,
                th.humidity,
                th.battery,
                th.rssi,
                DATE_FORMAT(th.timestamp, '%Y-%m-%d %H:%i') as timestamp,
                COALESCE(t.display_name, t.name, d.device_name, th.mac) as device_name,
                th.mac
            FROM thermometer_history th
            LEFT JOIN thermometers t ON th.mac = t.mac
            LEFT JOIN devices d ON th.mac = d.device
            WHERE th.timestamp >= DATE_SUB(NOW(), INTERVAL ? HOUR)
            ORDER BY th.timestamp ASC
        ");
        
        $stmt->execute([$hours]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format data for response
        $formattedHistory = array_map(function($record) {
            return [
                'device_name' => $record['device_name'],
                'mac' => $record['mac'],
                'temperature' => floatval($record['temperature']),
                'humidity' => floatval($record['humidity']),
                'battery' => intval($record['battery']),
                'rssi' => intval($record['rssi']),
                'timestamp' => $record['timestamp']
            ];
        }, $history);

        return sendSuccessResponse($response, [
            'history' => $formattedHistory
        ]);
        
    } catch (Exception $e) {
        return sendErrorResponse($response, $e);
    }
});

$app->get('/thermometer-list', function (Request $request, Response $response) use ($config) {
    try {
        $pdo = getDatabaseConnection($config);
        
        // Updated query to use room from thermometers table
        $stmt = $pdo->prepare("
            SELECT 
                t.mac,
                t.name,
                t.display_name,
                t.model,
                t.room as room_id,
                r.room_name,
                t.updated
            FROM thermometers t
            LEFT JOIN rooms r ON t.room = r.id
            ORDER BY t.name
        ");
        $stmt->execute();
        $thermometers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get room list for dropdown
        $roomStmt = $pdo->query("SELECT id, room_name FROM rooms WHERE id != 1 ORDER BY room_name");
        $rooms = $roomStmt->fetchAll(PDO::FETCH_ASSOC);

        return sendSuccessResponse($response, [
            'thermometers' => $thermometers,
            'rooms' => $rooms
        ]);
    } catch (Exception $e) {
        return sendErrorResponse($response, $e);
    }
});

$app->post('/update-thermometer', function (Request $request, Response $response) use ($config) {
    try {
        $data = json_decode($request->getBody()->getContents(), true);
        validateRequiredParams($data, ['mac']);
        
        $pdo = getDatabaseConnection($config);
        
        // Update thermometer display name and room
        $stmt = $pdo->prepare("
            UPDATE thermometers 
            SET display_name = ?,
                room = ?
            WHERE mac = ?
        ");
        
        $stmt->execute([
            $data['display_name'] ?: null,
            $data['room'] ?: null,
            $data['mac']
        ]);
        
        return sendSuccessResponse($response, ['message' => 'Thermometer updated successfully']);
        
    } catch (Exception $e) {
        return sendErrorResponse($response, $e);
    }
});

$app->get('/all-devices', function (Request $request, Response $response) use ($config) {
    try {
        $pdo = getDatabaseConnection($config);
        $stmt = $pdo->prepare("
            SELECT d.*, 
                   r.room_name,
                   g.name as group_name 
            FROM devices d
            LEFT JOIN rooms r ON d.room = r.id
            LEFT JOIN device_groups g ON d.deviceGroup = g.id
            ORDER BY d.brand, d.model, d.device_name
        ");
        $stmt->execute();
        
        // Get room list for dropdown
        $roomStmt = $pdo->query("SELECT id, room_name FROM rooms ORDER BY room_name");
        $rooms = $roomStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get group list
        $groupStmt = $pdo->query("SELECT id, name FROM device_groups ORDER BY name");
        $groups = $groupStmt->fetchAll(PDO::FETCH_ASSOC);

        return sendSuccessResponse($response, [
            'devices' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'rooms' => $rooms,
            'groups' => $groups
        ]);
    } catch (Exception $e) {
        return sendErrorResponse($response, $e);
    }
});

$app->post('/update-device-details', function (Request $request, Response $response) use ($config) {
    try {
        $data = json_decode($request->getBody()->getContents(), true);
        validateRequiredParams($data, ['device']);
        
        $pdo = getDatabaseConnection($config);
        
        $stmt = $pdo->prepare("
            UPDATE devices 
            SET x10Code = ?,
                preferredName = ?,
                room = ?,
                low = ?,
                medium = ?,
                high = ?,
                preferredPowerState = ?,
                preferredBrightness = ?,
                preferredColorTem = ?,
                deviceGroup = ?
            WHERE device = ?
        ");
        
        $stmt->execute([
            $data['x10Code'],
            $data['preferredName'],
            $data['room'],
            $data['low'],
            $data['medium'],
            $data['high'],
            $data['preferredPowerState'],
            $data['preferredBrightness'],
            $data['preferredColorTem'],
            $data['deviceGroup'],
            $data['device']
        ]);
        
        return sendSuccessResponse($response, ['message' => 'Device updated successfully']);
    } catch (Exception $e) {
        return sendErrorResponse($response, $e);
    }
});

$app->get('/update-vesync-devices', function (Request $request, Response $response) use ($config, $log) {
    try {
        $timing = [];
        $result = measureExecutionTime(function() use ($config, $log, &$timing) {
            // Initialize VeSync API
            $vesyncApi = new VeSyncAPI(
                $config['vesync_api']['user'], 
                $config['vesync_api']['password'],
                $config['db_config']
            );

            $api_timing = measureExecutionTime(function() use ($vesyncApi) {
                if (!$vesyncApi->login()) {
                    throw new Exception('Failed to login to VeSync API');
                }
                
                $devices = $vesyncApi->get_devices();
                if (!$devices) {
                    throw new Exception('Failed to get devices from VeSync API');
                }
                
                return $devices;
            });
            $timing['devices'] = ['duration' => $api_timing['duration']];

            $states_timing = measureExecutionTime(function() use ($vesyncApi, $api_timing) {
                $updated_devices = [];
                foreach ($api_timing['result'] as $type => $devices) {
                    foreach ($devices as $device) {
                        $vesyncApi->update_device_database($device);
                        $updated_devices[] = $device;
                    }
                }
                return $updated_devices;
            });
            
            $timing['states'] = ['duration' => $states_timing['duration']];
            
            return [
                'devices' => $states_timing['result'],
                'updated' => date('c'),
                'timing' => $timing
            ];
        });
        
        return sendSuccessResponse($response, $result['result']);
    } catch (Exception $e) {
        return sendErrorResponse($response, $e, $log);
    }
});

$app->run();