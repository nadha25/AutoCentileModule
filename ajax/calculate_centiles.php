<?php
/**
 * AJAX Handler: Calculate Centiles
 * Calls RCPCH API to calculate growth centiles
 */

// Properly initialize External Module context
try {
    // REDCap's External Module framework should be initialized
    // The module instance should be available via the global scope when called via getUrl()
    if (!isset($module)) {
        throw new Exception('Module context not available');
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Module initialization failed: ' . $e->getMessage()
    ]);
    exit;
}

// Set JSON header
header('Content-Type: application/json');

try {
    // Get POST data
    $rawInput = file_get_contents('php://input');
    if (!$rawInput) {
        throw new Exception('No input data received');
    }
    
    $input = json_decode($rawInput, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON: ' . json_last_error_msg());
    }
    
    // Validate required fields
    $required = ['birth_date', 'measurement_date', 'sex'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }
    
    // Get API configuration
    $apiKey = $module->getSystemSetting('rcpch_api_key');
    $apiUrl = 'https://api.rcpch.ac.uk/growth/v1/uk-who/calculation';
    
    // Prepare measurements array
    $measurements = [];
    
    // Weight measurement
    if (!empty($input['weight']) && is_numeric($input['weight'])) {
        $measurements[] = [
            'birth_date' => formatDate($input['birth_date']),
            'observation_date' => formatDate($input['measurement_date']),
            'observation_value' => floatval($input['weight']),
            'measurement_method' => 'weight',
            'sex' => $input['sex'] === '1' ? 'male' : 'female',
            'gestation_weeks' => !empty($input['gestation_weeks']) ? intval($input['gestation_weeks']) : 40,
            'gestation_days' => !empty($input['gestation_days']) ? intval($input['gestation_days']) : 0
        ];
    }
    
    // Height measurement
    if (!empty($input['height']) && is_numeric($input['height'])) {
        $measurements[] = [
            'birth_date' => formatDate($input['birth_date']),
            'observation_date' => formatDate($input['measurement_date']),
            'observation_value' => floatval($input['height']),
            'measurement_method' => !empty($input['measurement_method']) ? $input['measurement_method'] : 'height',
            'sex' => $input['sex'] === '1' ? 'male' : 'female',
            'gestation_weeks' => !empty($input['gestation_weeks']) ? intval($input['gestation_weeks']) : 40,
            'gestation_days' => !empty($input['gestation_days']) ? intval($input['gestation_days']) : 0
        ];
    }
    
    // BMI measurement (if both height and weight provided)
    if (!empty($input['weight']) && !empty($input['height']) 
        && is_numeric($input['weight']) && is_numeric($input['height'])) {
        
        $heightM = floatval($input['height']) / 100;
        if ($heightM > 0) {
            $bmi = floatval($input['weight']) / ($heightM * $heightM);
            
            $measurements[] = [
                'birth_date' => formatDate($input['birth_date']),
                'observation_date' => formatDate($input['measurement_date']),
                'observation_value' => round($bmi, 2),
                'measurement_method' => 'bmi',
                'sex' => $input['sex'] === '1' ? 'male' : 'female',
                'gestation_weeks' => !empty($input['gestation_weeks']) ? intval($input['gestation_weeks']) : 40,
                'gestation_days' => !empty($input['gestation_days']) ? intval($input['gestation_days']) : 0
            ];
        }
    }
    
    // Head circumference measurement
    if (!empty($input['ofc']) && is_numeric($input['ofc'])) {
        $measurements[] = [
            'birth_date' => formatDate($input['birth_date']),
            'observation_date' => formatDate($input['measurement_date']),
            'observation_value' => floatval($input['ofc']),
            'measurement_method' => 'ofc',
            'sex' => $input['sex'] === '1' ? 'male' : 'female',
            'gestation_weeks' => !empty($input['gestation_weeks']) ? intval($input['gestation_weeks']) : 40,
            'gestation_days' => !empty($input['gestation_days']) ? intval($input['gestation_days']) : 0
        ];
    }
    
    if (empty($measurements)) {
        throw new Exception('No valid measurements provided');
    }
    
    // Call RCPCH API for each measurement
    $results = [];
    foreach ($measurements as $measurement) {
        $response = callRCPCHAPI($apiUrl, $measurement, $apiKey);
        
        if ($response['success']) {
            $data = $response['data'];
            $method = $measurement['measurement_method'];
            
            $results[$method] = [
                'centile' => isset($data['measurement_calculated_values']['centile']) 
                    ? $data['measurement_calculated_values']['centile'] : null,
                'sds' => isset($data['measurement_calculated_values']['sds']) 
                    ? $data['measurement_calculated_values']['sds'] : null,
                'centile_band' => isset($data['measurement_calculated_values']['centile_band']) 
                    ? $data['measurement_calculated_values']['centile_band'] : null,
                'age_error' => isset($data['measurement_calculated_values']['chronological_decimal_age_error']) 
                    ? $data['measurement_calculated_values']['chronological_decimal_age_error'] : null,
                'corrected_age' => isset($data['measurement_calculated_values']['corrected_decimal_age']) 
                    ? $data['measurement_calculated_values']['corrected_decimal_age'] : null,
                'clinical_advice' => isset($data['measurement_calculated_values']['clinician_comment']) 
                    ? $data['measurement_calculated_values']['clinician_comment'] : null
            ];
        } else {
            $results[$method] = [
                'error' => $response['error']
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'results' => $results
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Format date to YYYY-MM-DD for API
 */
function formatDate($date) {
    if (empty($date)) {
        throw new Exception("Date cannot be empty");
    }
    
    // REDCap dates come in various formats, try to parse
    $formats = [
        'Y-m-d',      // Standard: 2024-01-15
        'd-m-Y',      // UK: 15-01-2024
        'd/m/Y',      // UK with slashes: 15/01/2024
        'm/d/Y',      // US: 01/15/2024
        'Y-m-d H:i:s' // With time
    ];
    
    foreach ($formats as $format) {
        $d = DateTime::createFromFormat($format, $date);
        if ($d !== false) {
            return $d->format('Y-m-d');
        }
    }
    
    // Fallback: try strtotime
    $timestamp = strtotime($date);
    if ($timestamp !== false) {
        return date('Y-m-d', $timestamp);
    }
    
    throw new Exception("Invalid date format: $date");
}

/**
 * Call RCPCH Growth API
 */
function callRCPCHAPI($url, $data, $apiKey = null) {
    if (!function_exists('curl_init')) {
        return [
            'success' => false,
            'error' => 'cURL extension not available'
        ];
    }
    
    $ch = curl_init($url);
    
    if ($ch === false) {
        return [
            'success' => false,
            'error' => 'Failed to initialize cURL'
        ];
    }
    
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json'
    ];
    
    // Add API key if provided
    if (!empty($apiKey)) {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
    }
    
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return [
            'success' => false,
            'error' => 'cURL error: ' . $error
        ];
    }
    
    if ($response === false) {
        return [
            'success' => false,
            'error' => 'No response from API'
        ];
    }
    
    if ($httpCode === 200) {
        $decodedResponse = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'error' => 'Invalid JSON response from API'
            ];
        }
        return [
            'success' => true,
            'data' => $decodedResponse
        ];
    } else {
        $errorData = json_decode($response, true);
        $errorMsg = 'API error: HTTP ' . $httpCode;
        if (is_array($errorData) && isset($errorData['detail'])) {
            $errorMsg = $errorData['detail'];
        } elseif (is_array($errorData) && isset($errorData['message'])) {
            $errorMsg = $errorData['message'];
        }
        return [
            'success' => false,
            'error' => $errorMsg,
            'http_code' => $httpCode
        ];
    }
}
