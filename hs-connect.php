<?php
// Fetch the exchange rates from the external API
function fetchExchangeRates() {
    $currentDate = date('Y-m-d\TH:i:s.v\Z'); 
    $url = "https://example-api-url.com/exchange/table?date=$currentDate";  
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code != 200) {
        die('Error: Unable to fetch exchange rates');
    }
    return json_decode($response, true);
}

// Fetch existing rows from HubDB
function fetchExistingRows($hubdb_table_id, $hubspot_api_key) {
    $url = "https://api.hubapi.com/cms/v3/hubdb/tables/$hubdb_table_id/rows";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $hubspot_api_key",
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

// Send data to HubDB via HubSpot API
function saveToHubDB($data) {
    $hubspot_api_key = 'your-api-key'; // API key for HubSpot
    $hubdb_table_id = 'your-hubdb-table-id'; // HubDB table ID

    // Fetch existing rows
    $existingRows = fetchExistingRows($hubdb_table_id, $hubspot_api_key);
    $existingCurrencies = [];

    // Create an associative array where currency is the key, and rowId is the value
    if (isset($existingRows['results']) && is_array($existingRows['results'])) {
        foreach ($existingRows['results'] as $row) {
            $currency = $row['values']['name'] ?? null;
            if ($currency) {
                $existingCurrencies[$currency] = $row['id'];
            }
        }
    }

    $url = "https://api.hubapi.com/cms/v3/hubdb/tables/$hubdb_table_id/rows";

    foreach ($data['data']['exchangeTableEntryViewList'] as $index => $entry) {
        if ($index % 2 !== 0) {
            continue; // Skip this iteration if the index is odd
        }

        $currency = trim((string)$entry['currency']);
        $payload = json_encode([
            'values' => [
                'name' => $currency,
                'valor_compra' => (string)$entry['buyValue'],
                'valor_venda' => (string)$entry['sellValue'],
                'data' => (string)$entry['lastUpdateDate'],
            ]
        ]);

        // Check if currency already exists in HubDB
        if (array_key_exists($currency, $existingCurrencies)) {
            $rowId = $existingCurrencies[$currency]; 
            $updateUrl = "$url/$rowId/draft";
    
            $ch = curl_init($updateUrl);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $hubspot_api_key,
                'Content-Type: application/json',
            ]);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT'); 
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload); 
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            $response = curl_exec($ch);
            curl_close($ch);

        } else {
            // Currency does not exist, perform POST
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer $hubspot_api_key",
                'Content-Type: application/json',
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            $response = curl_exec($ch);
            curl_close($ch);
        }
    }
    
    pushDraftToLive($hubdb_table_id, $hubspot_api_key);
}

// Push draft changes to live
function pushDraftToLive($hubdb_table_id, $hubspot_api_key) {
    $url = "https://api.hubapi.com/cms/v3/hubdb/tables/$hubdb_table_id/draft/publish";
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $hubspot_api_key,
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_POST, true); 
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    curl_close($ch);

    if ($response === FALSE) {
        die('Error occurred while pushing draft to live.');
    } else {
        echo 'Push to live successful!';
    }
}

// Main execution
$exchangeRates = fetchExchangeRates();
saveToHubDB($exchangeRates);
