<?php

// ===== RECEBE WEBHOOK DA DUTFY =====
$input = file_get_contents("php://input");
$data = json_decode($input, true);

file_put_contents("log_dutfy.txt", $input . PHP_EOL, FILE_APPEND);

$status = $data['status'] ?? null;

if ($status !== 'COMPLETED') {
    http_response_code(200);
    exit;
}

// ===== DADOS BASE =====
$transaction_id = $data['transactionId'] ?? ($data['_id']['$oid'] ?? uniqid("purchase_", true));

$processed_file = "processed_purchases.txt";
$processed = file_exists($processed_file) ? file($processed_file, FILE_IGNORE_NEW_LINES) : [];

if (in_array($transaction_id, $processed)) {
    http_response_code(200);
    exit;
}

file_put_contents($processed_file, $transaction_id . PHP_EOL, FILE_APPEND);

$amount_in_cents = intval($data['amount'] ?? 0);
$amount = $amount_in_cents / 100;

// ===== ENVIO PARA META CAPI =====
$pixel_id = "2392021021282065";
$access_token = "EAAjXgUXDJGIBQ9sZAx5v3GFRkZAy5nPNqiI1MLCJrpLJiL0MWTLFjvEpNwzgqMJvERK47oMtmBMbptPNhbw6r8N84P6WsjKvATHo27m4UQ4pn2sbDQYzZAy18ChahGBK2UwvT8hhvy9wHO4BK5mmJ254FZBle9QMNZBHvPrv9vUO3cEbf3GV1DhrVGXcQACr3eAZDZD";

$email = strtolower(trim($data['customer']['email'] ?? ''));
$phone = preg_replace('/\D/', '', $data['customer']['phone'] ?? '');
$document = preg_replace('/\D/', '', $data['customer']['document'] ?? '');

$user_data = [
    "client_ip_address" => $data['ip'] ?? null,
    "client_user_agent" => $data['userAgent'] ?? null
];

if ($email) {
    $user_data["em"] = [hash("sha256", $email)];
}

if ($phone) {
    $user_data["ph"] = [hash("sha256", $phone)];
}

if ($document) {
    $user_data["external_id"] = [hash("sha256", $document)];
}

$event = [
    "data" => [
        [
            "event_name" => "Purchase",
            "event_time" => time(),
            "event_id" => $transaction_id,
            "action_source" => "website",
            "event_source_url" => "https://vidadoabner.shop",
            "test_event_code" => "TEST87006",
            "user_data" => $user_data,
            "custom_data" => [
                "currency" => "BRL",
                "value" => $amount,
                "order_id" => $transaction_id,
                "content_name" => $data['items']['title'] ?? "Doa0Š40Š0o",
                "content_type" => "product"
            ]
        ]
    ]
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://graph.facebook.com/v19.0/$pixel_id/events?access_token=$access_token");
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($event));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);

$response_meta = curl_exec($ch);
curl_close($ch);

file_put_contents("log_meta_response.txt", $response_meta . PHP_EOL, FILE_APPEND);

// ===== ENVIO PARA UTMIFY =====
$utmify_token = "3VWTKir04v6FPHN2JisCDpxz176KPKUxsDiS";

$utm_string = $data['utm'] ?? '';
parse_str($utm_string, $utm_params);

$payload_utmify = [
    "isTest" => false,
    "orderId" => $transaction_id,
    "platform" => "Dutfy",
    "paymentMethod" => "pix",
    "status" => "paid",

    "createdAt" => $data['createdAt'] ?? date("c"),
    "approvedDate" => $data['approvedAt'] ?? date("c"),

    "customer" => [
        "name" => $data['customer']['name'] ?? "Cliente",
        "email" => $data['customer']['email'] ?? "sememail@cliente.com",
        "phone" => $data['customer']['phone'] ?? null,
        "document" => $data['customer']['document'] ?? null,
        "country" => "BR"
    ],

    "products" => [
    [
        "id" => "compra",
        "name" => $data['items']['title'] ?? "compra",
        "planId" => "default",
        "planName" => "compra",
        "quantity" => intval($data['items']['quantity'] ?? 1),
        "priceInCents" => $amount_in_cents
    ]
],

    "commission" => [
        "totalPriceInCents" => $amount_in_cents,
        "gatewayFeeInCents" => intval(($data['amount'] ?? 0) - ($data['result'] ?? 0)),
        "userCommissionInCents" => intval($data['result'] ?? $data['amount'] ?? 0)
    ],

    "trackingParameters" => [
        "utm_source" => $utm_params['utm_source'] ?? null,
        "utm_campaign" => $utm_params['utm_campaign'] ?? null,
        "utm_medium" => $utm_params['utm_medium'] ?? null,
        "utm_content" => $utm_params['utm_content'] ?? null,
        "utm_term" => $utm_params['utm_term'] ?? null
    ]
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://api.utmify.com.br/api-credentials/orders");
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload_utmify));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "x-api-token: $utmify_token"
]);

$response_utmify = curl_exec($ch);
curl_close($ch);

file_put_contents("log_utmify.txt", $response_utmify . PHP_EOL, FILE_APPEND);

http_response_code(200);
