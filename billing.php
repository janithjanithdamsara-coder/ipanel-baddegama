<?php
// billing.php
// Direct Counter Billing: checkout walk-in customers instantly, deduct stock, and print invoice.

require_once 'auth.php';
require_once 'db.php';

// Fetch all inventory items for selectors with stock details
try {
    $inventory_panels = $db->query("SELECT * FROM inventory WHERE category = 'panel' ORDER BY name ASC")->fetchAll();
    $inventory_profiles = $db->query("SELECT * FROM inventory WHERE category = 'profile' ORDER BY name ASC")->fetchAll();
    $inventory_accessories = $db->query("SELECT * FROM inventory WHERE category = 'accessory' ORDER BY name ASC")->fetchAll();
    $all_inventory = $db->query("SELECT * FROM inventory ORDER BY name ASC")->fetchAll();
} catch (PDOException $e) {
    error_log("Billing query failed: " . $e->getMessage());
    die("An error occurred while loading inventory data for billing.");
}

// Handle Direct Billing Checkout POST Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['checkout_bill'])) {
    enforce_csrf();

    $client_name = trim($_POST['client_name'] ?? '');
    if (empty($client_name)) {
        $client_name = 'Walk-in Customer';
    }
    $client_phone = trim($_POST['client_phone'] ?? '');
    $client_address = trim($_POST['client_address'] ?? '');
    
    $payment_method = $_POST['payment_method'] ?? 'Cash';
    $labor_cost = floatval($_POST['labor_cost'] ?? 0);
    $transport_cost = floatval($_POST['transport_cost'] ?? 0);
    $discount_percentage = floatval($_POST['discount_percentage'] ?? 0);
    $tax_percentage = floatval($_POST['tax_percentage'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    
    // Line items
    $post_item_ids = $_POST['item_id'] ?? [];
    $post_item_names = $_POST['item_name'] ?? [];
    $post_item_categories = $_POST['item_category'] ?? [];
    $post_unit_prices = $_POST['unit_price'] ?? [];
    $post_quantities = $_POST['quantity'] ?? [];
    $post_lengths = $_POST['length_ft'] ?? [];

    if (empty($post_item_names)) {
        $_SESSION['flash_msg'] = "Please add at least one item to the bill before checking out.";
        $_SESSION['flash_type'] = "danger";
        header("Location: billing.php");
        exit;
    }

    try {
        $db->beginTransaction();

        // 1. Create a dummy/direct project behind the scenes
        $stmt_proj = $db->prepare("INSERT INTO projects (client_name, client_phone, client_address, status) VALUES (?, ?, ?, 'Completed')");
        $stmt_proj->execute([$client_name, $client_phone, $client_address]);
        $project_id = $db->lastInsertId();

        // 2. Generate invoice number e.g. INV-2607-001
        $prefix = 'INV-' . date('ym') . '-';
        $month_start = date('Y-m-01 00:00:00');
        $stmt_count = $db->prepare("SELECT COUNT(*) FROM quotations WHERE created_at >= ? AND is_direct_bill = 1");
        $stmt_count->execute([$month_start]);
        $count = $stmt_count->fetchColumn() + 1;
        $invoice_number = $prefix . str_pad($count, 3, '0', STR_PAD_LEFT);

        // 3. Insert Quotation (representing this bill)
        $stmt_quote = $db->prepare("INSERT INTO quotations (project_id, quotation_number, labor_cost, transport_cost, discount_percentage, tax_percentage, notes, status, is_direct_bill, payment_method) VALUES (?, ?, ?, ?, ?, ?, ?, 'Accepted', 1, ?)");
        $stmt_quote->execute([$project_id, $invoice_number, $labor_cost, $transport_cost, $discount_percentage, $tax_percentage, $notes, $payment_method]);
        $quotation_id = $db->lastInsertId();

        // 4. Insert items and DEDUCT stock
        $stmt_item = $db->prepare("INSERT INTO quotation_items (quotation_id, item_id, item_name, item_category, unit_price, quantity, length_ft, total_price) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt_deduct = $db->prepare("UPDATE inventory SET stock = stock - ? WHERE id = ?");

        for ($i = 0; $i < count($post_item_names); $i++) {
            $item_id = !empty($post_item_ids[$i]) ? intval($post_item_ids[$i]) : null;
            $item_name = trim($post_item_names[$i]);
            $item_cat = trim($post_item_categories[$i] ?? 'other');
            $unit_price = floatval($post_unit_prices[$i]);
            $qty = intval($post_quantities[$i]);
            $length = !empty($post_lengths[$i]) ? floatval($post_lengths[$i]) : null;
            
            $total_price = $unit_price * $qty;
            
            if (!empty($item_name) && $qty > 0) {
                // Insert quotation item
                $stmt_item->execute([$quotation_id, $item_id, $item_name, $item_cat, $unit_price, $qty, $length, $total_price]);
                
                // Deduct stock if linked to inventory
                if ($item_id > 0) {
                    $stmt_deduct->execute([$qty, $item_id]);
                }
            }
        }

        $db->commit();
        
        log_activity($db, 'issue_invoice', "Issued Direct Invoice {$invoice_number} to '{$client_name}' (Paid via: {$payment_method})");

        $_SESSION['flash_msg'] = "Direct Invoice " . $invoice_number . " completed and stock updated.";
        $_SESSION['flash_type'] = "success";
        
        header("Location: print_invoice.php?id=" . $quotation_id);
        exit;

    } catch (PDOException $e) {
        $db->rollBack();
        error_log("Checkout error: " . $e->getMessage());
        $_SESSION['flash_msg'] = "Checkout transaction failed. Please try again.";
        $_SESSION['flash_type'] = "danger";
        header("Location: billing.php");
        exit;
    }
}

require_once 'header.php';
?>

<div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
    <div>
        <h2 style="font-size: 24px; text-transform: uppercase;">Direct Billing & Counter Sales</h2>
        <p style="color: var(--text-muted); font-size: 13px;">Create cash bills instantly. Inventory levels will be automatically deducted upon checkout.</p>
    </div>
    <a href="inventory.php" class="btn btn-secondary btn-sm" style="font-weight: 700;">View Live Inventory Stock &rarr;</a>
</div>

<!-- Flash Message Notifications -->
<?php if (isset($_SESSION['flash_msg'])): ?>
    <div style="padding: 14px 20px; margin-bottom: 24px; border-radius: var(--radius-sm); font-size: 15px; font-weight: 600; 
                background-color: <?= $_SESSION['flash_type'] === 'success' ? 'var(--success-glow)' : 'var(--danger-glow)' ?>; 
                border-left: 4px solid <?= $_SESSION['flash_type'] === 'success' ? 'var(--success)' : 'var(--danger)' ?>; 
                color: <?= $_SESSION['flash_type'] === 'success' ? '#065f46' : '#991b1b' ?>;">
        <?= htmlspecialchars($_SESSION['flash_msg']) ?>
    </div>
    <?php 
    unset($_SESSION['flash_msg']);
    unset($_SESSION['flash_type']);
    ?>
<?php endif; ?>

<form action="billing.php" method="POST" id="billingForm">
    <?= csrf_field() ?>
    <input type="hidden" name="checkout_bill" value="1">
    
    <div class="content-layout two-col">
        <!-- LEFT COLUMN: Invoice Items and Client Info -->
        <div>
            <!-- Client Info Card -->
            <div class="section-card" style="margin-bottom: 20px;">
                <h3 class="section-title" style="font-size: 16px; margin-bottom: 16px; color: var(--primary-brand); border-bottom: 1px solid var(--border-color); padding-bottom: 8px;">1. Client & Billing Details</h3>
                
                <div class="form-row">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="client_name">Client Name</label>
                        <input type="text" id="client_name" name="client_name" placeholder="Walk-in Customer (Default)" value="Walk-in Customer">
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="client_phone">Phone Number</label>
                        <input type="tel" id="client_phone" name="client_phone" placeholder="e.g. 0771234567">
                    </div>
                </div>
                
                <div class="form-group" style="margin-top: 16px; margin-bottom: 0;">
                    <label for="client_address">Delivery Address (Optional)</label>
                    <textarea id="client_address" name="client_address" rows="2" placeholder="Site delivery address if applicable..."></textarea>
                </div>
            </div>

            <!-- Add Item Selector Toolbar -->
            <div class="section-card" style="margin-bottom: 20px;">
                <h3 class="section-title" style="font-size: 16px; margin-bottom: 16px; color: var(--primary-brand); border-bottom: 1px solid var(--border-color); padding-bottom: 8px;">2. Add Materials to Bill</h3>
                
                <div class="form-row" style="align-items: flex-end; margin-bottom: 0;">
                    <div class="form-group" style="flex: 2; margin-bottom: 0;">
                        <label for="itemSelect">Select Product from Inventory</label>
                        <select id="itemSelect" onchange="populateSelectedItem()">
                            <option value="">-- Choose Inventory Item --</option>
                            <optgroup label="Ceiling Panels">
                                <?php foreach ($inventory_panels as $p): ?>
                                    <option value="<?= $p['id'] ?>" data-name="<?= htmlspecialchars($p['name']) ?>" data-category="panel" data-price="<?= $p['price'] ?>" data-length="<?= $p['length_ft'] ?: '' ?>" data-stock="<?= $p['stock'] ?>">
                                        <?= htmlspecialchars($p['name']) ?> (Rs. <?= number_format($p['price'], 2) ?>) - Stock: <?= $p['stock'] ?> units
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="Finishing Series / Profiles">
                                <?php foreach ($inventory_profiles as $p): ?>
                                    <option value="<?= $p['id'] ?>" data-name="<?= htmlspecialchars($p['name']) ?>" data-category="profile" data-price="<?= $p['price'] ?>" data-length="<?= $p['length_ft'] ?: '' ?>" data-stock="<?= $p['stock'] ?>">
                                        <?= htmlspecialchars($p['name']) ?> (Rs. <?= number_format($p['price'], 2) ?>) - Stock: <?= $p['stock'] ?> units
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="Accessories">
                                <?php foreach ($inventory_accessories as $a): ?>
                                    <option value="<?= $a['id'] ?>" data-name="<?= htmlspecialchars($a['name']) ?>" data-category="accessory" data-price="<?= $a['price'] ?>" data-length="<?= $a['length_ft'] ?: '' ?>" data-stock="<?= $a['stock'] ?>">
                                        <?= htmlspecialchars($a['name']) ?> (Rs. <?= number_format($a['price'], 2) ?>) - Stock: <?= $a['stock'] ?> units
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </div>
                    
                    <div class="form-group" style="width: 80px; margin-bottom: 0;">
                        <label for="itemQty">Qty</label>
                        <input type="number" id="itemQty" value="1" min="1" onkeyup="checkStockLimit()" onchange="checkStockLimit()">
                    </div>
                    
                    <div style="margin-bottom: 0;">
                        <button type="button" onclick="addProductToBill()" class="btn btn-primary" style="height: 46px; border-radius: var(--radius-sm);">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 2px;"><line x1="12" x2="12" y1="5" y2="19"></line><line x1="5" x2="19" y1="12" y2="12"></line></svg>
                            Add to Bill
                        </button>
                    </div>
                </div>

                <!-- Stock limit warning banner -->
                <div id="stockWarning" style="display: none; margin-top: 12px; padding: 10px; background-color: var(--danger-glow); border-left: 3px solid var(--danger); font-size: 13px; color: var(--danger); font-weight: 600;">
                    Warning: The quantity entered exceeds the current stock level.
                </div>
            </div>

            <!-- Itemized Table Card -->
            <div class="section-card">
                <h3 class="section-title" style="margin-bottom: 16px;">Direct Bill Line Items</h3>
                
                <div style="overflow-x: auto;">
                    <table class="app-table" id="itemsTable">
                        <thead>
                            <tr>
                                <th>Item details</th>
                                <th style="width: 100px; text-align: center;">Length (ft)</th>
                                <th style="width: 120px; text-align: right;">Unit Price (Rs.)</th>
                                <th style="width: 90px; text-align: center;">Qty</th>
                                <th style="width: 120px; text-align: right;">Total (Rs.)</th>
                                <th style="width: 80px; text-align: right;">Remove</th>
                            </tr>
                        </thead>
                        <tbody id="quotationItemsBody">
                            <tr id="emptyItemsRow">
                                <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 30px;">
                                    No items added yet. Search items above or use the Ceiling Area Calculator.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- RIGHT COLUMN: Billing Adjustments, Payment & Calculator Widget -->
        <div>
            <!-- Payment Settings Card -->
            <div class="section-card" style="margin-bottom: 20px; padding: 24px;">
                <h3 class="section-title" style="font-size: 16px; margin-bottom: 16px; color: var(--primary-brand); border-bottom: 1px solid var(--border-color); padding-bottom: 8px;">3. Payment Settings</h3>
                
                <div class="form-group">
                    <label for="payment_method">Payment Method</label>
                    <select id="payment_method" name="payment_method" style="font-weight: 700;">
                        <option value="Cash">💵 Cash</option>
                        <option value="Card">💳 Card Payment</option>
                        <option value="Credit">🕒 Credit (Paid Later)</option>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="labor_cost">Labor Cost (Rs.)</label>
                        <input type="number" id="labor_cost" name="labor_cost" step="0.01" value="0.00" onchange="recalculateTotals()">
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="transport_cost">Transport Cost (Rs.)</label>
                        <input type="number" id="transport_cost" name="transport_cost" step="0.01" value="0.00" onchange="recalculateTotals()">
                    </div>
                </div>

                <div class="form-row" style="margin-top: 16px;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="discount_percentage">Discount (%)</label>
                        <input type="number" id="discount_percentage" name="discount_percentage" step="0.1" min="0" max="100" value="0.0" onchange="recalculateTotals()">
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="tax_percentage">Tax (%)</label>
                        <input type="number" id="tax_percentage" name="tax_percentage" step="0.1" min="0" max="100" value="0.0" onchange="recalculateTotals()">
                    </div>
                </div>

                <div class="form-group" style="margin-top: 16px;">
                    <label for="notes">Invoice Note / Terms</label>
                    <textarea id="notes" name="notes" rows="2" placeholder="e.g. Goods sold are not returnable. Thank you!"></textarea>
                </div>
            </div>

            <!-- Interactive Ceiling Panel Calculator Widget -->
            <div class="section-card calculator-widget" style="margin-bottom: 20px;">
                <div class="calculator-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="color: var(--primary-brand);"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="9" x2="15" y1="9" y2="15"></line><line x1="15" x2="9" y1="9" y2="15"></line></svg>
                    Quick Ceiling Area Calculator
                </div>
                
                <div class="form-row">
                    <div class="form-group" style="margin-bottom: 10px;">
                        <label for="room_width">Room Width (Ft)</label>
                        <input type="number" id="room_width" step="0.1" placeholder="Width" onkeyup="calculateCeilingMetrics()">
                    </div>
                    <div class="form-group" style="margin-bottom: 10px;">
                        <label for="room_length">Room Length (Ft)</label>
                        <input type="number" id="room_length" step="0.1" placeholder="Length" onkeyup="calculateCeilingMetrics()">
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 10px;">
                    <label for="calc_panel_id">Ceiling Panel Type</label>
                    <select id="calc_panel_id" onchange="calculateCeilingMetrics()">
                        <option value="">-- Choose Panel to Calculate --</option>
                        <?php foreach ($inventory_panels as $p): ?>
                            <option value="<?= $p['id'] ?>" data-name="<?= htmlspecialchars($p['name']) ?>" data-price="<?= $p['price'] ?>" data-width="<?= $p['width_mm'] ?>" data-length="<?= $p['length_ft'] ?>" data-stock="<?= $p['stock'] ?>">
                                <?= htmlspecialchars($p['name']) ?> (Stock: <?= $p['stock'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom: 12px;">
                    <label>Panel Direction</label>
                    <div style="display: flex; gap: 16px; font-size: 13px; margin-top: 4px;">
                        <label style="display: inline-flex; align-items: center; gap: 6px; font-weight: normal;">
                            <input type="radio" name="panel_dir" value="length" checked onchange="calculateCeilingMetrics()"> Along Length
                        </label>
                        <label style="display: inline-flex; align-items: center; gap: 6px; font-weight: normal;">
                            <input type="radio" name="panel_dir" value="width" onchange="calculateCeilingMetrics()"> Along Width
                        </label>
                    </div>
                </div>

                <!-- Calculator Results Preview -->
                <div class="calculator-result" id="calcResults" style="display: none;">
                    <div class="result-item">
                        Panels Needed
                        <span id="panelsCount">0</span>
                    </div>
                    <div class="result-item">
                        Corner Profiles (10ft)
                        <span id="cornersCount">0</span>
                    </div>
                    <div class="result-item" style="grid-column: span 2; border-top: 1px dashed var(--border-color); padding-top: 8px;">
                        Est. Material Cost
                        <span id="materialCost" style="color: var(--primary-brand); font-size: 18px;">Rs. 0.00</span>
                    </div>
                    <div style="grid-column: span 2; margin-top: 10px;">
                        <button type="button" onclick="applyCalculatorResults()" class="btn btn-primary btn-sm" style="width: 100%; font-weight: 700; border-radius: var(--radius-sm);">Apply to Invoice list</button>
                    </div>
                </div>
            </div>

            <!-- Billing Totals Summary Card -->
            <div class="section-card" style="padding: 24px;">
                <h4 style="text-transform: uppercase; font-size: 12px; margin-bottom: 16px; border-bottom: 1px solid var(--border-color); padding-bottom: 8px; color: var(--text-dark);">Invoice Totals</h4>
                
                <div style="background-color: var(--bg-main); padding: 16px; border-left: 4px solid var(--primary-brand);">
                    <table class="totals-table">
                        <tr>
                            <td>Materials Subtotal:</td>
                            <td style="text-align: right; font-weight: 600;" id="summaryMaterials">Rs. 0.00</td>
                        </tr>
                        <tr>
                            <td>Labor & Transport:</td>
                            <td style="text-align: right;" id="summaryLaborTransport">Rs. 0.00</td>
                        </tr>
                        <tr id="discountRow" style="color: var(--danger); display: none;">
                            <td>Discount:</td>
                            <td style="text-align: right;" id="summaryDiscount">-Rs. 0.00</td>
                        </tr>
                        <tr id="taxRow" style="color: var(--warning); display: none;">
                            <td>Tax:</td>
                            <td style="text-align: right;" id="summaryTax">+Rs. 0.00</td>
                        </tr>
                        <tr class="grand-total">
                            <td>Grand Total:</td>
                            <td style="text-align: right;" id="summaryGrandTotal">Rs. 0.00</td>
                        </tr>
                    </table>
                </div>

                <div style="margin-top: 24px;">
                    <button type="submit" class="btn btn-success" style="width: 100%; font-weight: 700; font-size: 16px; padding: 14px;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px;"><path d="M20 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        Save & Print Bill
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
    let selectedItemBuffer = null;

    // Load initial totals
    window.addEventListener('DOMContentLoaded', () => {
        recalculateTotals();
    });

    // Populate item buffer on selection
    function populateSelectedItem() {
        const select = document.getElementById('itemSelect');
        const selectedOption = select.options[select.selectedIndex];
        
        if (select.value === '') {
            selectedItemBuffer = null;
            document.getElementById('stockWarning').style.display = 'none';
            return;
        }

        selectedItemBuffer = {
            id: select.value,
            name: selectedOption.getAttribute('data-name'),
            category: selectedOption.getAttribute('data-category'),
            price: parseFloat(selectedOption.getAttribute('data-price')),
            length: selectedOption.getAttribute('data-length') ? parseFloat(selectedOption.getAttribute('data-length')) : null,
            stock: parseInt(selectedOption.getAttribute('data-stock')) || 0
        };

        checkStockLimit();
    }

    // Check if entered qty exceeds stock levels
    function checkStockLimit() {
        const warningBox = document.getElementById('stockWarning');
        if (!selectedItemBuffer) {
            warningBox.style.display = 'none';
            return;
        }
        
        const qty = parseInt(document.getElementById('itemQty').value) || 1;
        if (qty > selectedItemBuffer.stock) {
            warningBox.style.display = 'block';
            warningBox.textContent = `Warning: Selected quantity (${qty}) exceeds the available stock level (${selectedItemBuffer.stock}).`;
        } else {
            warningBox.style.display = 'none';
        }
    }

    // Add selected item to the invoice list
    function addProductToBill() {
        if (!selectedItemBuffer) {
            alert('Please select a product from the inventory.');
            return;
        }

        const qty = parseInt(document.getElementById('itemQty').value) || 1;
        const length = selectedItemBuffer.length ? selectedItemBuffer.length : '';
        
        addItemRow(selectedItemBuffer.id, selectedItemBuffer.name, selectedItemBuffer.category, selectedItemBuffer.price, qty, length);
        
        // Reset inputs
        document.getElementById('itemSelect').value = '';
        document.getElementById('itemQty').value = '1';
        document.getElementById('stockWarning').style.display = 'none';
        selectedItemBuffer = null;
    }

    // Append table row helper
    function addItemRow(id, name, category, price, qty, length) {
        const body = document.getElementById('quotationItemsBody');
        const emptyRow = document.getElementById('emptyItemsRow');
        
        if (emptyRow) {
            body.removeChild(emptyRow);
        }

        // Check if item is already added to sum quantities
        const existingRows = document.querySelectorAll('.quote-item-row');
        for (let row of existingRows) {
            const rowItemId = row.querySelector('input[name="item_id[]"]').value;
            if (rowItemId === id && id !== '') {
                const qtyInput = row.querySelector('input[name="quantity[]"]');
                qtyInput.value = parseInt(qtyInput.value) + qty;
                recalculateTotals();
                showToast("Added quantity to existing line item.");
                return;
            }
        }

        const tr = document.createElement('tr');
        tr.className = 'quote-item-row';
        tr.innerHTML = `
            <td data-label="Item details">
                <input type="hidden" name="item_id[]" value="${id}">
                <input type="hidden" name="item_name[]" value="${escapeHtml(name)}">
                <input type="hidden" name="item_category[]" value="${escapeHtml(category)}">
                <span style="font-weight: 600;">${escapeHtml(name)}</span>
                <span class="badge" style="background: var(--primary-brand); color: #fff; font-size: 8px; margin-left: 6px;">${category}</span>
            </td>
            <td data-label="Length (ft)">
                <input type="number" name="length_ft[]" value="${length}" step="0.1" style="width: 80px; text-align: center;" onchange="recalculateTotals()">
            </td>
            <td data-label="Unit Price">
                <input type="number" name="unit_price[]" value="${price.toFixed(2)}" step="0.01" style="width: 100px; text-align: right;" onchange="recalculateTotals()">
            </td>
            <td data-label="Qty">
                <input type="number" name="quantity[]" value="${qty}" min="1" style="width: 70px; text-align: center;" onchange="recalculateTotals()">
            </td>
            <td data-label="Total (Rs.)" style="text-align: right; font-weight: 600;" class="item-row-total">
                Rs. ${(price * qty).toFixed(2)}
            </td>
            <td data-label="Action" style="text-align: right;">
                <button type="button" class="btn btn-danger btn-sm" style="font-weight: 700; padding: 4px 10px;" onclick="removeProductFromBill(this)">&times;</button>
            </td>
        `;
        body.appendChild(tr);
        recalculateTotals();
    }

    // Remove row
    function removeProductFromBill(buttonEl) {
        const row = buttonEl.closest('tr');
        row.parentNode.removeChild(row);
        
        const body = document.getElementById('quotationItemsBody');
        if (body.children.length === 0) {
            const tr = document.createElement('tr');
            tr.id = 'emptyItemsRow';
            tr.innerHTML = `
                <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 30px;">
                    No items added yet. Search items above or use the Ceiling Area Calculator.
                </td>
            `;
            body.appendChild(tr);
        }
        recalculateTotals();
    }

    // Math Calculations
    function recalculateTotals() {
        const rows = document.querySelectorAll('.quote-item-row');
        let materialsSubtotal = 0;

        rows.forEach(row => {
            const price = parseFloat(row.querySelector('input[name="unit_price[]"]').value) || 0;
            const qty = parseInt(row.querySelector('input[name="quantity[]"]').value) || 0;
            const itemTotal = price * qty;
            
            row.querySelector('.item-row-total').textContent = 'Rs. ' + itemTotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            materialsSubtotal += itemTotal;
        });

        const labor = parseFloat(document.getElementById('labor_cost').value) || 0;
        const transport = parseFloat(document.getElementById('transport_cost').value) || 0;
        const discountPercentage = parseFloat(document.getElementById('discount_percentage').value) || 0;
        const taxPercentage = parseFloat(document.getElementById('tax_percentage').value) || 0;

        const servicesSubtotal = labor + transport;
        const subtotal = materialsSubtotal + servicesSubtotal;
        
        const discountAmount = subtotal * (discountPercentage / 100);
        const subtotalAfterDiscount = subtotal - discountAmount;
        
        const taxAmount = subtotalAfterDiscount * (taxPercentage / 100);
        const grandTotal = subtotalAfterDiscount + taxAmount;

        // Render summary displays
        document.getElementById('summaryMaterials').textContent = 'Rs. ' + materialsSubtotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        document.getElementById('summaryLaborTransport').textContent = 'Rs. ' + servicesSubtotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        
        const discountRow = document.getElementById('discountRow');
        if (discountPercentage > 0) {
            discountRow.style.display = '';
            document.getElementById('summaryDiscount').textContent = '-Rs. ' + discountAmount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        } else {
            discountRow.style.display = 'none';
        }

        const taxRow = document.getElementById('taxRow');
        if (taxPercentage > 0) {
            taxRow.style.display = '';
            document.getElementById('summaryTax').textContent = '+Rs. ' + taxAmount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        } else {
            taxRow.style.display = 'none';
        }

        document.getElementById('summaryGrandTotal').textContent = 'Rs. ' + grandTotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    // Ceiling Material Area calculations
    let calcOutputs = null;

    function calculateCeilingMetrics() {
        const w_ft = parseFloat(document.getElementById('room_width').value) || 0;
        const l_ft = parseFloat(document.getElementById('room_length').value) || 0;
        const select = document.getElementById('calc_panel_id');
        const selectedOption = select.options[select.selectedIndex];
        
        const resultsBox = document.getElementById('calcResults');

        if (w_ft <= 0 || l_ft <= 0 || !select.value) {
            resultsBox.style.display = 'none';
            calcOutputs = null;
            return;
        }

        const price = parseFloat(selectedOption.getAttribute('data-price')) || 0;
        const width_mm = parseFloat(selectedOption.getAttribute('data-width')) || 200;
        const length_ft = parseFloat(selectedOption.getAttribute('data-length')) || 10;
        const stock = parseInt(selectedOption.getAttribute('data-stock')) || 0;

        const dir = document.querySelector('input[name="panel_dir"]:checked').value;

        let dimensionAlongPanel, dimensionAcrossPanel;
        if (dir === 'length') {
            dimensionAlongPanel = l_ft;
            dimensionAcrossPanel = w_ft;
        } else {
            dimensionAlongPanel = w_ft;
            dimensionAcrossPanel = l_ft;
        }

        const dimensionAcrossPanel_mm = dimensionAcrossPanel * 304.8;
        const rowsCount = Math.ceil(dimensionAcrossPanel_mm / width_mm);
        const panelsPerRow = Math.ceil(dimensionAlongPanel / length_ft);
        const totalPanels = rowsCount * panelsPerRow;

        // Perimeter Profile A
        const perimeter_ft = 2 * (w_ft + l_ft);
        const totalCorners = Math.ceil(perimeter_ft / 10);
        const cornerPrice = 480;

        const totalCost = (totalPanels * price) + (totalCorners * cornerPrice);

        document.getElementById('panelsCount').textContent = totalPanels + ` (Stock: ${stock})`;
        document.getElementById('cornersCount').textContent = totalCorners;
        document.getElementById('materialCost').textContent = 'Rs. ' + totalCost.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

        resultsBox.style.display = '';

        calcOutputs = {
            panelId: select.value,
            panelName: selectedOption.getAttribute('data-name'),
            panelPrice: price,
            panelQty: totalPanels,
            panelLength: length_ft,
            cornerPrice: cornerPrice,
            cornerQty: totalCorners
        };
    }

    function applyCalculatorResults() {
        if (!calcOutputs) return;

        addItemRow(calcOutputs.panelId, calcOutputs.panelName, 'panel', calcOutputs.panelPrice, calcOutputs.panelQty, calcOutputs.panelLength);
        
        // Add corner profiles defaults
        addItemRow('', 'i-Panel finishing perimeter (Profile A equivalent)', 'profile', calcOutputs.cornerPrice, calcOutputs.cornerQty, 10);

        // Reset
        document.getElementById('room_width').value = '';
        document.getElementById('room_length').value = '';
        document.getElementById('calc_panel_id').value = '';
        document.getElementById('calcResults').style.display = 'none';
        calcOutputs = null;

        showToast("Calculator results added to invoice list!");
    }

    function escapeHtml(text) {
        if (!text) return '';
        return text
            .toString()
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    // Sidebar overlay toggle helper (for responsive view)
    function toggleSidebar() {
        const sidebar = document.getElementById('appSidebar');
        sidebar.classList.toggle('active');
    }

    // Helper toast display logic
    function showToast(message) {
        const toast = document.getElementById('toast');
        const toastMsg = document.getElementById('toast-message');
        if (toast && toastMsg) {
            toastMsg.textContent = message;
            toast.className = "toast show toast-success";
            setTimeout(() => {
                toast.className = "toast";
            }, 3000);
        }
    }
</script>

<?php require_once 'footer.php'; ?>
