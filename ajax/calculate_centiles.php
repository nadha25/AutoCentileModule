<?php
/**
 * AJAX Handler: Calculate Centiles
 * Calls RCPCH API to calculate growth centiles
 */

// Get the module instance
$module = $this;

// Set JSON header
header('Content-Type: application/json');

try {
    // Get POST data
    $input = json_decode(file_get_contents('php://input'), true);
    
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
    if (!empty($input['weight'])) {
        $measurements[] = [
            'birth_date' => formatDate($input['birth_date']),
            'observation_date' => formatDate($input['measurement_date']),
            'observation_value' => floatval($input['weight']),
            'measurement_method' => 'weight',
            'sex' => $input['sex'] === '1' ? 'male' : 'female',
            'gestation_weeks' => intval($input['gestation_weeks'] ?? 40),
            'gestation_days' => intval($input['gestation_days'] ?? 0)
        ];
    }
    
    // Height measurement
    if (!empty($input['height'])) {
        $measurements[] = [
            'birth_date' => formatDate($input['birth_date']),
            'observation_date' => formatDate($input['measurement_date']),
            'observation_value' => floatval($input['height']),
            'measurement_method' => $input['measurement_method'] ?? 'height',
            'sex' => $input['sex'] === '1' ? 'male' : 'female',
            'gestation_weeks' => intval($input['gestation_weeks'] ?? 40),
            'gestation_days' => intval($input['gestation_days'] ?? 0)
        ];
    }
    
    // BMI measurement (if both height and weight provided)
    if (!empty($input['weight']) && !empty($input['height'])) {
        $bmi = floatval($input['weight']) / pow(floatval($input['height']) / 100, 2);
        
        $measurements[] = [
            'birth_date' => formatDate($input['birth_date']),
            'observation_date' => formatDate($input['measurement_date']),
            'observation_value' => round($bmi, 2),
            'measurement_method' => 'bmi',
            'sex' => $input['sex'] === '1' ? 'male' : 'female',
            'gestation_weeks' => intval($input['gestation_weeks'] ?? 40),
            'gestation_days' => intval($input['gestation_days'] ?? 0)
        ];
    }
    
    // Head circumference measurement
    if (!empty($input['ofc'])) {
        $measurements[] = [
            'birth_date' => formatDate($input['birth_date']),
            'observation_date' => formatDate($input['measurement_date']),
            'observation_value' => floatval($input['ofc']),
            'measurement_method' => 'ofc',
            'sex' => $input['sex'] === '1' ? 'male' : 'female',
            'gestation_weeks' => intval($input['gestation_weeks'] ?? 40),
            'gestation_days' => intval($input['gestation_days'] ?? 0)
        ];
    }
    
    if (empty($measurements)) {
        throw new Exception('No measurements provided');
    }
    
    // Call RCPCH API for each measurement
    $results = [];
    foreach ($measurements as $measurement) {
        $response = callRCPCHAPI($apiUrl, $measurement, $apiKey);
        
        if ($response['success']) {
            $data = $response['data'];
            $method = $measurement['measurement_method'];
            
            $results[$method] = [
                'centile' => $data['measurement_calculated_values']['centile'] ?? null,
                'sds' => $data['measurement_calculated_values']['sds'] ?? null,
                'centile_band' => $data['measurement_calculated_values']['centile_band'] ?? null,
                'age_error' => $data['measurement_calculated_values']['chronological_decimal_age_error'] ?? null,
                'corrected_age' => $data['measurement_calculated_values']['corrected_decimal_age'] ?? null,
                'clinical_advice' => $data['measurement_calculated_values']['clinician_comment'] ?? null
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
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Format date to YYYY-MM-DD for API
 */
function formatDate($date) {
    // REDCap dates come in various formats, try to parse
    $formats = ['d-m-Y', 'd/m/Y', 'Y-m-d', 'd-m-Y H:i', 'd/m/Y H:i'];
    
    foreach ($formats as $format) {
        $d = DateTime::createFromFormat($format, $date);
        if ($d && $d->format($format) === $date) {
            return $d->format('Y-m-d');
        }
    }
    
    // Fallback: try strtotime
    $timestamp = strtotime($date);
    if ($timestamp) {
        return date('Y-m-d', $timestamp);
    }
    
    throw new Exception("Invalid date format: $date");
}

/**
 * Call RCPCH Growth API
 */
function callRCPCHAPI($url, $data, $apiKey = null) {
    $ch = curl_init($url);
    
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json'
    ];
    
    // Add API key if provided
    if ($apiKey) {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
    }
    
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true
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
    
    if ($httpCode === 200) {
        return [
            'success' => true,
            'data' => json_decode($response, true)
        ];
    } else {
        $errorData = json_decode($response, true);
        return [
            'success' => false,
            'error' => $errorData['detail'] ?? 'API error: HTTP ' . $httpCode
        ];
    }
}
