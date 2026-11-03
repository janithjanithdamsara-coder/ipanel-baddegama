<?php
// print_invoice.php
// Printable receipt page for direct bills / retail invoices

require_once 'auth.php';
require_once 'db.php';

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    die("Error: Invalid invoice ID.");
}

try {
    // Fetch quotation metadata (representing the invoice)
    $stmt = $db->prepare("SELECT * FROM quotations WHERE id = ? AND is_direct_bill = 1");
    $stmt->execute([$id]);
    $invoice = $stmt->fetch();
    
    if (!$invoice) {
        die("Error: Direct Invoice not found.");
    }
    
    // Fetch project/client details (representing customer details)
    $stmt = $db->prepare("SELECT * FROM projects WHERE id = ?");
    $stmt->execute([$invoice['project_id']]);
    $project = $stmt->fetch();
    
    // Fetch line items
    $stmt = $db->prepare("SELECT * FROM quotation_items WHERE quotation_id = ?");
    $stmt->execute([$id]);
    $items = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log("Invoice query error: " . $e->getMessage());
    die("An error occurred while loading the invoice.");
}

// Calculate totals
$items_total = 0;
foreach ($items as $item) {
    $items_total += $item['total_price'];
}

$subtotal = $items_total + $invoice['labor_cost'] + $invoice['transport_cost'];
$discount_amount = $subtotal * ($invoice['discount_percentage'] / 100);
$taxable_amount = $subtotal - $discount_amount;
$tax_amount = $taxable_amount * ($invoice['tax_percentage'] / 100);
$grand_total = $taxable_amount + $tax_amount;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice_<?= htmlspecialchars($invoice['quotation_number']) ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        body {
            background-color: #ffffff;
            font-size: 14px;
            color: #333333;
        }
        
        .print-sheet {
            background: #ffffff;
            max-width: 800px;
            margin: 30px auto;
            padding: 40px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            position: relative;
        }

        .action-bar {
            max-width: 800px;
            margin: 20px auto 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .badge-status {
            display: inline-block;
            font-size: 11px;
            font-weight: 700;
            padding: 6px 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border: 1.5px solid;
        }

        .badge-payment {
            background-color: #ecfdf5;
            border-color: #a7f3d0;
            color: #047857;
        }

        @media print {
            body {
                background-color: #ffffff;
                color: #000000;
            }
            .print-sheet {
                border: none;
                box-shadow: none;
                margin: 0;
                padding: 0;
                max-width: 100%;
            }
            .action-bar, .no-print {
                display: none !important;
            }
        }
    </style>
</head>
<body>

    <!-- Action Toolbar (Hidden during printing) -->
    <div class="action-bar no-print">
        <a href="billing.php" class="btn btn-secondary btn-sm" style="font-weight:700;">&larr; Back to Billing Screen</a>
        <div style="display: flex; gap: 8px;">
            <button onclick="window.print()" class="btn btn-primary">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px;"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                Print Invoice / Save PDF
            </button>
        </div>
    </div>

    <!-- Printable Invoice Sheet -->
    <div class="print-sheet">
        <!-- Header -->
        <div style="display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #0D121F; padding-bottom: 20px; margin-bottom: 24px;">
            <div>
                <h1 style="font-size: 24px; font-weight: 800; text-transform: uppercase; color: #0D121F;">
                    i-Panel <span style="color: #0056FF;">Baddegama</span> Ceilings
                </h1>
                <p style="font-size: 12px; color: #666666; margin-top: 4px;">
                    Baddegama Branch, Sri Lanka.<br>
                    Tel: 091-2292350 | Email: baddegama@ipanel.lk<br>
                    Web: https://ipanel.lk
                </p>
            </div>
            <div style="text-align: right;">
                <h2 style="font-size: 20px; text-transform: uppercase; color: #0056FF; font-weight: 800; letter-spacing: 0.5px; margin-bottom: 6px;">RETAIL INVOICE</h2>
                <div style="font-size: 13px; font-weight: 700; color: #0D121F;"><?= htmlspecialchars($invoice['quotation_number']) ?></div>
                <div style="font-size: 12px; color: #666666; margin-top: 4px;">Date: <?= date('Y-m-d H:i', strtotime($invoice['created_at'])) ?></div>
                <div style="margin-top: 8px;">
                    <span class="badge-status badge-payment">Paid via: <?= htmlspecialchars($invoice['payment_method']) ?></span>
                </div>
            </div>
        </div>

        <!-- Addresses -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px;">
            <div>
                <h4 style="font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #666666; margin-bottom: 6px;">Client / Customer Details</h4>
                <div style="font-weight: 700; font-size: 15px; color: #0D121F;"><?= htmlspecialchars($project['client_name']) ?></div>
                <?php if (!empty($project['client_phone'])): ?>
                    <div style="font-size: 13px; margin-top: 4px; color: #333333;">Phone: <?= htmlspecialchars($project['client_phone']) ?></div>
                <?php endif; ?>
            </div>
            <div>
                <?php if (!empty($project['client_address'])): ?>
                    <h4 style="font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #666666; margin-bottom: 6px;">Delivery Address</h4>
                    <div style="font-size: 13px; line-height: 1.5; color: #333333;">
                        <?= nl2br(htmlspecialchars($project['client_address'])) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Line Items Table -->
        <table class="app-table" style="margin-bottom: 24px;">
            <thead>
                <tr>
                    <th style="background-color: #f8fafc; border-bottom: 1.5px solid #cbd5e1; color: #0D121F;">Ceiling Material / Accessory Details</th>
                    <th style="background-color: #f8fafc; border-bottom: 1.5px solid #cbd5e1; color: #0D121F; text-align: center; width: 100px;">Length (ft)</th>
                    <th style="background-color: #f8fafc; border-bottom: 1.5px solid #cbd5e1; color: #0D121F; text-align: right; width: 120px;">Rate (Rs.)</th>
                    <th style="background-color: #f8fafc; border-bottom: 1.5px solid #cbd5e1; color: #0D121F; text-align: center; width: 80px;">Qty</th>
                    <th style="background-color: #f8fafc; border-bottom: 1.5px solid #cbd5e1; color: #0D121F; text-align: right; width: 120px;">Amount (Rs.)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td>
                            <div style="font-weight: 600; color: #0D121F;"><?= htmlspecialchars($item['item_name']) ?></div>
                            <div style="font-size: 11px; color: #666666; text-transform: capitalize;">Category: <?= htmlspecialchars($item['item_category']) ?></div>
                        </td>
                        <td style="text-align: center; color: #333333;"><?= $item['length_ft'] ?: '-' ?></td>
                        <td style="text-align: right; color: #333333;"><?= number_format($item['unit_price'], 2) ?></td>
                        <td style="text-align: center; color: #333333;"><?= $item['quantity'] ?></td>
                        <td style="text-align: right; font-weight: 600; color: #0D121F;"><?= number_format($item['total_price'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Calculations Summary -->
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-top: 30px;">
            <!-- Terms/Notes -->
            <div style="max-width: 50%; font-size: 11px; color: #666666; line-height: 1.6;">
                <h4 style="font-size: 11px; text-transform: uppercase; color: #0D121F; font-weight: 700; margin-bottom: 6px; letter-spacing: 0.5px;">Terms & Conditions</h4>
                <div style="background-color: #f8fafc; padding: 10px; border-left: 2px solid #0056FF;">
                    <?= !empty($invoice['notes']) ? nl2br(htmlspecialchars($invoice['notes'])) : "1. Goods sold are in good condition and not returnable.<br>2. Thank you for your purchase!" ?>
                </div>
            </div>
            
            <!-- Calculations Box -->
            <div style="width: 250px; background-color: #f8fafc; border: 1px solid #e2e8f0; padding: 16px;">
                <table style="width: 100%; font-size: 13px; border-collapse: collapse;">
                    <tr>
                        <td style="padding: 4px 0; color: #666666;">Materials Total:</td>
                        <td style="padding: 4px 0; text-align: right; font-weight: 600; color: #0D121F;">Rs. <?= number_format($items_total, 2) ?></td>
                    </tr>
                    <?php if ($invoice['labor_cost'] > 0): ?>
                        <tr>
                            <td style="padding: 4px 0; color: #666666;">Labor / Install:</td>
                            <td style="padding: 4px 0; text-align: right; color: #0D121F;">Rs. <?= number_format($invoice['labor_cost'], 2) ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($invoice['transport_cost'] > 0): ?>
                        <tr>
                            <td style="padding: 4px 0; color: #666666;">Transport:</td>
                            <td style="padding: 4px 0; text-align: right; color: #0D121F;">Rs. <?= number_format($invoice['transport_cost'], 2) ?></td>
                        </tr>
                    <?php endif; ?>
                    
                    <?php if ($invoice['discount_percentage'] > 0): ?>
                        <tr style="color: #dc2626;">
                            <td style="padding: 4px 0;">Discount (<?= $invoice['discount_percentage'] ?>%):</td>
                            <td style="padding: 4px 0; text-align: right;">-Rs. <?= number_format($discount_amount, 2) ?></td>
                        </tr>
                    <?php endif; ?>

                    <?php if ($invoice['tax_percentage'] > 0): ?>
                        <tr style="color: #f59e0b;">
                            <td style="padding: 4px 0;">Tax (<?= $invoice['tax_percentage'] ?>%):</td>
                            <td style="padding: 4px 0; text-align: right;">+Rs. <?= number_format($tax_amount, 2) ?></td>
                        </tr>
                    <?php endif; ?>

                    <tr style="border-top: 1.5px solid #0D121F; font-size: 15px; font-weight: 700;">
                        <td style="padding: 10px 0 0 0; color: #0056FF;">Grand Total:</td>
                        <td style="padding: 10px 0 0 0; text-align: right; color: #0056FF;">Rs. <?= number_format($grand_total, 2) ?></td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Signatures (Visible when printed) -->
        <div style="display: flex; justify-content: space-between; margin-top: 80px; padding-top: 10px;">
            <div style="text-align: center; width: 220px;">
                <div style="border-top: 1px solid #999999; padding-top: 8px; font-size: 11px; font-weight: 700; color: #333333; text-transform: uppercase; letter-spacing: 0.5px;">
                    Issued By
                </div>
                <div style="font-size: 10px; color: #666666; margin-top: 4px;">i-Panel Baddegama Branch</div>
            </div>
            
            <div style="text-align: center; width: 220px;">
                <div style="border-top: 1px solid #999999; padding-top: 8px; font-size: 11px; font-weight: 700; color: #333333; text-transform: uppercase; letter-spacing: 0.5px;">
                    Customer Signature
                </div>
                <div style="font-size: 10px; color: #666666; margin-top: 4px;">Thank you for your business!</div>
            </div>
        </div>
    </div>

</body>
</html>
