<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/includes/security.php';
require_once dirname(__DIR__, 3) . '/includes/config.php';
require_once dirname(__DIR__, 3) . '/includes/functions.php';

require_customer_auth('/account/order/invoice/');
$customer = current_customer();
if ($customer === null) {
    redirect(resolve_link('/account/login/'));
}

$orderId = clean_string($_GET['id'] ?? '', 80);
$order = customer_order_by_id($customer, $orderId);
if ($order === null) {
    site_flash_set('error', 'Order record was not found for this account.');
    redirect(resolve_link('/account/'));
}

$presented = order_presenter_data($order, $customer);
$pdf = order_invoice_pdf_bytes($presented);
$filename = (string) ($presented['invoice_file_name'] ?? 'invoice.pdf');

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, max-age=0, must-revalidate');
echo $pdf;
exit;
