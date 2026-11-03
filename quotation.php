<?php
// quotation.php
// Quotation editor: create/edit quotations, calculate ceiling panels, manage line items

require_once 'auth.php';
require_once 'db.php';

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;
$is_edit = ($id > 0);

$quote_data = null;
$project_data = null;
$quote_items = [];

// Initialize or load edit data
if ($is_edit) {
    try {
        $stmt = $db->prepare("SELECT * FROM quotations WHERE id = ?");
        $stmt->execute([$id]);
        $quote_data = $stmt->fetch();
        
        if ($quote_data) {
            $project_id = $quote_data['project_id'];
            
            // Fetch quotation items
            $stmt = $db->prepare("SELECT * FROM quotation_items WHERE quotation_id = ?");
            $stmt->execute([$id]);
            $quote_items = $stmt->fetchAll();
        } else {
            $_SESSION['flash_msg'] = "Quotation not found.";
            $_SESSION['flash_type'] = "danger";
            header("Location: projects.php");
            exit;
        }
    } catch (PDOException $e) {
        error_log("Quotation fetch failed: " . $e->getMessage());
        die("An error occurred while loading quotation details.");
    }
}

// Fetch project details
if ($project_id > 0) {
    try {
        $stmt = $db->prepare("SELECT * FROM projects WHERE id = ?");
        $stmt->execute([$project_id]);
        $project_data = $stmt->fetch();
        
        if (!$project_data) {
            $_SESSION['flash_msg'] = "Associated project not found.";
            $_SESSION['flash_type'] = "danger";
            header("Location: projects.php");
            exit;
        }
    } catch (PDOException $e) {
        error_log("Project fetch failed: " . $e->getMessage());
        die("An error occurred while loading project details.");
    }
} else {
    $_SESSION['flash_msg'] = "No project selected.";
    $_SESSION['flash_type'] = "danger";
    header("Location: projects.php");
    exit;
}

// Fetch all active inventory items to select in the quote builder
try {
    $inventory_panels = $db->query("SELECT * FROM inventory WHERE category = 'panel' AND stock > 0 ORDER BY name ASC")->fetchAll();
    $inventory_profiles = $db->query("SELECT * FROM inventory WHERE category = 'profile' AND stock > 0 ORDER BY name ASC")->fetchAll();
    $inventory_accessories = $db->query("SELECT * FROM inventory WHERE category = 'accessory' AND stock > 0 ORDER BY name ASC")->fetchAll();
    $all_inventory = $db->query("SELECT * FROM inventory ORDER BY name ASC")->fetchAll();
} catch (PDOException $e) {
    error_log("Inventory query failed: " . $e->getMessage());
    die("An error occurred while loading inventory products.");
}

// Handle Form Submission (Save Quotation)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_quotation'])) {
    enforce_csrf();

    $labor_cost = floatval($_POST['labor_cost'] ?? 0);
    $transport_cost = floatval($_POST['transport_cost'] ?? 0);
    $discount_percentage = floatval($_POST['discount_percentage'] ?? 0);
    $tax_percentage = floatval($_POST['tax_percentage'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $status = $_POST['status'] ?? 'Draft';
    
    // Post items arrays
    $post_item_ids = $_POST['item_id'] ?? [];
    $post_item_names = $_POST['item_name'] ?? [];
    $post_item_categories = $_POST['item_category'] ?? [];
    $post_unit_prices = $_POST['unit_price'] ?? [];
    $post_quantities = $_POST['quantity'] ?? [];
    $post_lengths = $_POST['length_ft'] ?? [];

    try {
        $db->beginTransaction();

        if ($is_edit) {
            // Update quotation metadata
            $stmt = $db->prepare("UPDATE quotations SET labor_cost = ?, transport_cost = ?, discount_percentage = ?, tax_percentage = ?, notes = ?, status = ? WHERE id = ?");
            $stmt->execute([$labor_cost, $transport_cost, $discount_percentage, $tax_percentage, $notes, $status, $id]);
            
            // Delete existing quotation items and re-insert
            $stmt = $db->prepare("DELETE FROM quotation_items WHERE quotation_id = ?");
            $stmt->execute([$id]);
            
            $quotation_id = $id;
            $q_num = $quote_data['quotation_number'] ?? "ID #$id";
            log_activity($db, 'edit_quotation', "Updated quotation {$q_num} for project ID {$project_id}");
        } else {
            // Generate a quotation number e.g. QT-2607-001
            $prefix = 'QT-' . date('ym') . '-';
            $month_start = date('Y-m-01 00:00:00');
            $stmt = $db->prepare("SELECT COUNT(*) FROM quotations WHERE created_at >= ?");
            $stmt->execute([$month_start]);
            $count = $stmt->fetchColumn() + 1;
            $quotation_number = $prefix . str_pad($count, 3, '0', STR_PAD_LEFT);

            // Insert quotation metadata
            $stmt = $db->prepare("INSERT INTO quotations (project_id, quotation_number, labor_cost, transport_cost, discount_percentage, tax_percentage, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$project_id, $quotation_number, $labor_cost, $transport_cost, $discount_percentage, $tax_percentage, $notes, $status]);
            $quotation_id = $db->lastInsertId();

            log_activity($db, 'create_quotation', "Created quotation {$quotation_number} for project ID {$project_id}");
        }

        // Insert quotation items
        $stmt_item = $db->prepare("INSERT INTO quotation_items (quotation_id, item_id, item_name, item_category, unit_price, quantity, length_ft, total_price) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        
        for ($i = 0; $i < count($post_item_ids); $i++) {
            $item_id = !empty($post_item_ids[$i]) ? intval($post_item_ids[$i]) : null;
            $item_name = trim($post_item_names[$i]);
            $item_cat = trim($post_item_categories[$i] ?? 'other');
            $unit_price = floatval($post_unit_prices[$i]);
            $qty = intval($post_quantities[$i]);
            $length = !empty($post_lengths[$i]) ? floatval($post_lengths[$i]) : null;
            
            $total_price = $unit_price * $qty;
            
            if (!empty($item_name) && $qty > 0) {
                $stmt_item->execute([$quotation_id, $item_id, $item_name, $item_cat, $unit_price, $qty, $length, $total_price]);
            }
        }

        $db->commit();
        
        $_SESSION['flash_msg'] = "Quotation saved successfully.";
        $_SESSION['flash_type'] = "success";
        
        header("Location: projects.php?view_id=" . $project_id);
        exit;
        
    } catch (PDOException $e) {
        $db->rollBack();
        error_log("Error saving quotation: " . $e->getMessage());
        $_SESSION['flash_msg'] = "A database error occurred while saving the quotation.";
        $_SESSION['flash_type'] = "danger";
    }
}

require_once 'header.php';
?>

<div style="margin-bottom: 20px;">
    <a href="projects.php?view_id=<?= $project_id ?>" class="btn btn-secondary btn-sm" style="font-weight:700;">&larr; Back to Project Profile</a>
</div>

<div style="margin-bottom: 24px;">
    <h2><?= $is_edit ? 'Edit Itemized Quotation: ' . htmlspecialchars($quote_data['quotation_number']) : 'New Quotation Builder' ?></h2>
    <p style="color: var(--text-muted); font-size: 13px;">Create items, use the ceiling panels calculator, and configure billing rules.</p>
</div>

<form action="quotation.php?<?= $is_edit ? 'id=' . $id : 'project_id=' . $project_id ?>" method="POST" id="quotationForm">
    <?= csrf_field() ?>
    <input type="hidden" name="save_quotation" value="1">
    
    <div class="quotation-grid">
        <!-- LEFT COLUMN: Item Builder -->
        <div>
            <!-- Add Item Toolbar -->
            <div class="section-card" style="margin-bottom: 20px;">
                <h4 style="text-transform: uppercase; font-size: 12px; margin-bottom: 12px; color: var(--primary-brand);">Add Materials / Panels to Bill</h4>
                
                <div class="form-row" style="align-items: flex-end;">
                    <div class="form-group" style="flex: 2; margin-bottom: 0;">
                        <label for="itemSelect">Select Product from Inventory</label>
                        <select id="itemSelect" onchange="populateSelectedItem()">
                            <option value="">-- Choose Inventory Item --</option>
                            <optgroup label="Ceiling Panels">
                                <?php foreach ($inventory_panels as $p): ?>
                                    <option value="<?= $p['id'] ?>" data-name="<?= htmlspecialchars($p['name']) ?>" data-category="panel" data-price="<?= $p['price'] ?>" data-length="<?= $p['length_ft'] ?: '' ?>">
                                        <?= htmlspecialchars($p['name']) ?> (Rs. <?= number_format($p['price'], 2) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="Finishing Series / Profiles">
                                <?php foreach ($inventory_profiles as $p): ?>
                                    <option value="<?= $p['id'] ?>" data-name="<?= htmlspecialchars($p['name']) ?>" data-category="profile" data-price="<?= $p['price'] ?>" data-length="<?= $p['length_ft'] ?: '' ?>">
                                        <?= htmlspecialchars($p['name']) ?> (Rs. <?= number_format($p['price'], 2) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="Accessories">
                                <?php foreach ($inventory_accessories as $a): ?>
                                    <option value="<?= $a['id'] ?>" data-name="<?= htmlspecialchars($a['name']) ?>" data-category="accessory" data-price="<?= $a['price'] ?>" data-length="<?= $a['length_ft'] ?: '' ?>">
                                        <?= htmlspecialchars($a['name']) ?> (Rs. <?= number_format($a['price'], 2) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </div>
                    
                    <div class="form-group" style="width: 80px; margin-bottom: 0;">
                        <label for="itemQty">Qty</label>
                        <input type="number" id="itemQty" value="1" min="1">
                    </div>
                    
                    <div style="margin-bottom: 0;">
                        <button type="button" onclick="addProductToBill()" class="btn btn-primary" style="height: 46px; border-radius: var(--radius-sm);">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 2px;"><line x1="12" x2="12" y1="5" y2="19"></line><line x1="5" x2="19" y1="12" y2="12"></line></svg>
                            Add Item
                        </button>
                    </div>
                </div>
                
                <p style="margin-top: 10px; font-size: 11px; color: var(--text-muted);">
                    Don't see your panel size/color? Update it in the <a href="inventory.php" target="_blank" style="text-decoration: underline;">Inventory tab</a> first.
                </p>
            </div>

            <!-- Itemized Table Card -->
            <div class="section-card">
                <h3 class="section-title" style="margin-bottom: 16px;">Itemized Estimate Details</h3>
                
                <div style="overflow-x: auto;">
                    <table class="app-table" id="itemsTable">
                        <thead>
                            <tr>
                                <th>Item details</th>
                                <th style="width: 100px; text-align: center;">Length (ft)</th>
                                <th style="width: 120px; text-align: right;">Unit Price (Rs.)</th>
                                <th style="width: 90px; text-align: center;">Qty</th>
                                <th style="width: 120px; text-align: right;">Total (Rs.)</th>
                                <th style="width: 80px; text-align: right;" class="no-print">Remove</th>
                            </tr>
                        </thead>
                        <tbody id="quotationItemsBody">
                            <!-- JS populated rows -->
                            <?php if (empty($quote_items)): ?>
                                <tr id="emptyItemsRow">
                                    <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 30px;">
                                        No items added. Use the selector above or the Ceiling Calculator on the right.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($quote_items as $index => $item): ?>
                                    <tr class="quote-item-row">
                                        <td data-label="Item details">
                                            <input type="hidden" name="item_id[]" value="<?= $item['item_id'] ?>">
                                            <input type="hidden" name="item_name[]" value="<?= htmlspecialchars($item['item_name']) ?>">
                                            <input type="hidden" name="item_category[]" value="<?= htmlspecialchars($item['item_category']) ?>">
                                            <span style="font-weight:600;"><?= htmlspecialchars($item['item_name']) ?></span>
                                            <span class="badge" style="background: var(--secondary-brand); color: #fff; font-size: 8px; margin-left: 6px;"><?= $item['item_category'] ?></span>
                                        </td>
                                        <td data-label="Length (ft)">
                                            <input type="number" name="length_ft[]" value="<?= $item['length_ft'] ?>" step="0.1" style="width: 80px; text-align: center;" onchange="recalculateTotals()">
                                        </td>
                                        <td data-label="Unit Price">
                                            <input type="number" name="unit_price[]" value="<?= $item['unit_price'] ?>" step="0.01" style="width: 100px; text-align: right;" onchange="recalculateTotals()">
                                        </td>
                                        <td data-label="Qty">
                                            <input type="number" name="quantity[]" value="<?= $item['quantity'] ?>" min="1" style="width: 70px; text-align: center;" onchange="recalculateTotals()">
                                        </td>
                                        <td data-label="Total (Rs.)" style="text-align: right; font-weight: 600;" class="item-row-total">
                                            Rs. <?= number_format($item['total_price'], 2) ?>
                                        </td>
                                        <td data-label="Action" style="text-align: right;" class="no-print">
                                            <button type="button" class="btn btn-danger btn-sm" style="font-weight: 700;" onclick="removeProductFromBill(this)">&times;</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- RIGHT COLUMN: Project Info, Quotation Options, Calculator Widget -->
        <div>
            <!-- Client Info Card -->
            <div class="section-card" style="margin-bottom: 20px; padding: 20px;">
                <h4 style="text-transform: uppercase; font-size: 11px; color: var(--text-muted); margin-bottom: 8px;">Client Details</h4>
                <div style="font-weight: 700; font-size: 15px; color: var(--secondary-brand);"><?= htmlspecialchars($project_data['client_name']) ?></div>
                <div style="font-size: 13px; color: var(--text-muted); margin-top: 4px;">Phone: <?= htmlspecialchars($project_data['client_phone']) ?></div>
                <div style="font-size: 12px; color: var(--text-muted); margin-top: 4px; border-top: 1px solid var(--border-color); padding-top: 8px; max-height: 50px; overflow: hidden; text-overflow: ellipsis;">
                    Site: <?= htmlspecialchars($project_data['client_address']) ?>
                </div>
            </div>

            <!-- Interactive Ceiling Panel Calculator Widget -->
            <div class="section-card calculator-widget" style="margin-bottom: 20px;">
                <div class="calculator-title">Ceiling Area Calculator</div>
                
                <div class="form-row">
                    <div class="form-group" style="margin-bottom: 10px;">
                        <label for="room_width">Room Width (Ft)</label>
                        <input type="number" id="room_width" step="0.1" placeholder="e.g. 12" onkeyup="calculateCeilingMetrics()">
                    </div>
                    <div class="form-group" style="margin-bottom: 10px;">
                        <label for="room_length">Room Length (Ft)</label>
                        <input type="number" id="room_length" step="0.1" placeholder="e.g. 15" onkeyup="calculateCeilingMetrics()">
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 10px;">
                    <label for="calc_panel_id">Ceiling Panel Type</label>
                    <select id="calc_panel_id" onchange="calculateCeilingMetrics()">
                        <option value="">-- Choose Panel to Calculate --</option>
                        <?php foreach ($inventory_panels as $p): ?>
                            <option value="<?= $p['id'] ?>" data-name="<?= htmlspecialchars($p['name']) ?>" data-price="<?= $p['price'] ?>" data-width="<?= $p['width_mm'] ?>" data-length="<?= $p['length_ft'] ?>">
                                <?= htmlspecialchars($p['name']) ?> (<?= $p['width_mm'] ?>mm Width x <?= $p['length_ft'] ?>ft)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom: 12px;">
                    <label>Panel Direction</label>
                    <div style="display: flex; gap: 16px; font-size: 13px; margin-top: 4px;">
                        <label style="display: inline-flex; align-items: center; gap: 6px; font-weight: normal;">
                            <input type="radio" name="panel_dir" value="length" checked onchange="calculateCeilingMetrics()"> Run Along Length
                        </label>
                        <label style="display: inline-flex; align-items: center; gap: 6px; font-weight: normal;">
                            <input type="radio" name="panel_dir" value="width" onchange="calculateCeilingMetrics()"> Run Along Width
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
                    <div class="result-item" style="grid-column: span 2; border-top: 1px dashed var(--border-focus); padding-top: 8px;">
                        Estimated Material cost
                        <span id="materialCost" style="color: var(--primary-brand); font-size: 18px;">Rs. 0.00</span>
                    </div>
                    <div style="grid-column: span 2; margin-top: 10px;">
                        <button type="button" onclick="applyCalculatorResults()" class="btn btn-primary btn-sm" style="width: 100%; font-weight:700; border-radius: var(--radius-sm);">Apply to Estimate List</button>
                    </div>
                </div>
            </div>

            <!-- Billing Rules / Summaries Card -->
            <div class="section-card" style="padding: 24px;">
                <h4 style="text-transform: uppercase; font-size: 12px; margin-bottom: 16px; border-bottom: 1px solid var(--border-color); padding-bottom: 8px;">Billing Variables</h4>
                
                <div class="form-group">
                    <label for="labor_cost">Labor / Installation Cost (Rs.)</label>
                    <input type="number" id="labor_cost" name="labor_cost" step="0.01" value="<?= $is_edit ? $quote_data['labor_cost'] : '0.00' ?>" onchange="recalculateTotals()">
                </div>

                <div class="form-group">
                    <label for="transport_cost">Transport Cost (Rs.)</label>
                    <input type="number" id="transport_cost" name="transport_cost" step="0.01" value="<?= $is_edit ? $quote_data['transport_cost'] : '0.00' ?>" onchange="recalculateTotals()">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="discount_percentage">Discount (%)</label>
                        <input type="number" id="discount_percentage" name="discount_percentage" step="0.1" min="0" max="100" value="<?= $is_edit ? $quote_data['discount_percentage'] : '0.0' ?>" onchange="recalculateTotals()">
                    </div>
                    <div class="form-group">
                        <label for="tax_percentage">Tax (%)</label>
                        <input type="number" id="tax_percentage" name="tax_percentage" step="0.1" min="0" max="100" value="<?= $is_edit ? $quote_data['tax_percentage'] : '0.0' ?>" onchange="recalculateTotals()">
                    </div>
                </div>

                <div class="form-group">
                    <label for="status_select">Quotation Status</label>
                    <select id="status_select" name="status">
                        <option value="Draft" <?= ($is_edit && $quote_data['status'] == 'Draft') ? 'selected' : '' ?>>Draft</option>
                        <option value="Sent" <?= ($is_edit && $quote_data['status'] == 'Sent') ? 'selected' : '' ?>>Sent to Client</option>
                        <option value="Accepted" <?= ($is_edit && $quote_data['status'] == 'Accepted') ? 'selected' : '' ?>>Accepted (Contracted)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="notes">Quotation Terms & Notes</label>
                    <textarea id="notes" name="notes" rows="3" placeholder="e.g. Validity: 30 days. Payment terms: 50% advance, 50% on completion."><?= $is_edit ? htmlspecialchars($quote_data['notes']) : '' ?></textarea>
                </div>

                <!-- Final Calculations -->
                <div style="background-color: var(--bg-main); padding: 16px; margin-top: 24px; border-left: 4px solid var(--secondary-brand);">
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
                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px;"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                        Save Quotation Estimate
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

<!-- Complete Client side math engine -->
<script>
    // Selected options buffer
    let selectedItemBuffer = null;

    // Load initial counts
    window.addEventListener('DOMContentLoaded', () => {
        recalculateTotals();
    });

    // Populate buffer on selection changes
    function populateSelectedItem() {
        const select = document.getElementById('itemSelect');
        const selectedOption = select.options[select.selectedIndex];
        
        if (select.value === '') {
            selectedItemBuffer = null;
            return;
        }

        selectedItemBuffer = {
            id: select.value,
            name: selectedOption.getAttribute('data-name'),
            category: selectedOption.getAttribute('data-category'),
            price: parseFloat(selectedOption.getAttribute('data-price')),
            length: selectedOption.getAttribute('data-length') ? parseFloat(selectedOption.getAttribute('data-length')) : null
        };
    }

    // Add manual item to bill
    function addProductToBill() {
        if (!selectedItemBuffer) {
            alert('Please select a product from the inventory dropdown.');
            return;
        }

        const qty = parseInt(document.getElementById('itemQty').value) || 1;
        const length = selectedItemBuffer.length ? selectedItemBuffer.length : '';
        
        // Append row
        addItemRow(selectedItemBuffer.id, selectedItemBuffer.name, selectedItemBuffer.category, selectedItemBuffer.price, qty, length);
        
        // Reset buffers
        document.getElementById('itemSelect').value = '';
        document.getElementById('itemQty').value = '1';
        selectedItemBuffer = null;
    }

    // Append row helper
    function addItemRow(id, name, category, price, qty, length) {
        const body = document.getElementById('quotationItemsBody');
        const emptyRow = document.getElementById('emptyItemsRow');
        
        if (emptyRow) {
            body.removeChild(emptyRow);
        }

        const tr = document.createElement('tr');
        tr.className = 'quote-item-row';
        tr.innerHTML = `
            <td data-label="Item details">
                <input type="hidden" name="item_id[]" value="${id}">
                <input type="hidden" name="item_name[]" value="${escapeHtml(name)}">
                <input type="hidden" name="item_category[]" value="${escapeHtml(category)}">
                <span style="font-weight:600;">${escapeHtml(name)}</span>
                <span class="badge" style="background: var(--secondary-brand); color: #fff; font-size: 8px; margin-left: 6px;">${category}</span>
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
            <td data-label="Action" style="text-align: right;" class="no-print">
                <button type="button" class="btn btn-danger btn-sm" style="font-weight: 700;" onclick="removeProductFromBill(this)">&times;</button>
            </td>
        `;
        body.appendChild(tr);
        recalculateTotals();
    }

    // Delete item from list
    function removeProductFromBill(buttonEl) {
        const row = buttonEl.closest('tr');
        row.parentNode.removeChild(row);
        
        const body = document.getElementById('quotationItemsBody');
        if (body.children.length === 0) {
            const tr = document.createElement('tr');
            tr.id = 'emptyItemsRow';
            tr.innerHTML = `
                <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 30px;">
                    No items added. Use the selector above or the Ceiling Calculator on the right.
                </td>
            `;
            body.appendChild(tr);
        }
        recalculateTotals();
    }

    // Dynamic Calculation Engine
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

        // Render summary fields
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

    // Ceiling Material Calculator Logic
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

        // Get panel specifications
        const price = parseFloat(selectedOption.getAttribute('data-price')) || 0;
        const width_mm = parseFloat(selectedOption.getAttribute('data-width')) || 200; // default 200mm
        const length_ft = parseFloat(selectedOption.getAttribute('data-length')) || 10; // default 10ft

        const dir = document.querySelector('input[name="panel_dir"]:checked').value;

        let dimensionAlongPanel, dimensionAcrossPanel;
        
        if (dir === 'length') {
            // Panels run along length. 
            // e.g. Room Length is 15ft, Panels run this direction.
            // Width direction is covered by stacking panels side by side.
            dimensionAlongPanel = l_ft;
            dimensionAcrossPanel = w_ft;
        } else {
            // Panels run along width.
            dimensionAlongPanel = w_ft;
            dimensionAcrossPanel = l_ft;
        }

        // 1. Calculate how many panels fit side by side.
        // Convert dimensionAcrossPanel (feet) to millimeters: ft * 304.8
        const dimensionAcrossPanel_mm = dimensionAcrossPanel * 304.8;
        const rowsCount = Math.ceil(dimensionAcrossPanel_mm / width_mm);

        // 2. Calculate how many panels are needed per row.
        // E.g. Room dimension is 15ft, panel is 10ft long. We need 15/10 = 1.5 panels per row (so 2 panels per row).
        const panelsPerRow = Math.ceil(dimensionAlongPanel / length_ft);

        // Total panels needed
        const totalPanels = rowsCount * panelsPerRow;

        // 3. Perimeter corners (Profile A / TOP Corners).
        // Perimeter of the ceiling = 2 * (Width + Length) in feet.
        const perimeter_ft = 2 * (w_ft + l_ft);
        // Standard perimeter profiles are 10 feet long.
        const totalCorners = Math.ceil(perimeter_ft / 10);

        // Fetch Corner price (Profile A defaults, let's look for a corner price from the database)
        // For calculation purposes, let's assume a default profile/corner price of Rs. 480 or search dynamically.
        // We will default to Rs. 450 per corner profile.
        const cornerPrice = 480;

        const totalCost = (totalPanels * price) + (totalCorners * cornerPrice);

        // Update displays
        document.getElementById('panelsCount').textContent = totalPanels + ` (Length: ${length_ft}ft)`;
        document.getElementById('cornersCount').textContent = totalCorners + ` (10ft Profiles)`;
        document.getElementById('materialCost').textContent = 'Rs. ' + totalCost.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

        resultsBox.style.display = '';

        // Save outcomes in memory
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

        // Add calculated Panels
        addItemRow(calcOutputs.panelId, calcOutputs.panelName, 'panel', calcOutputs.panelPrice, calcOutputs.panelQty, calcOutputs.panelLength);

        // Add calculated Corner Profiles.
        // Let's attempt to look up if there is a 'Profile A' or 'TOP Corner' in the select items. 
        // We can just add it as a finishing profile.
        addItemRow('', 'i-Panel finishing perimeter (Profile A equivalent)', 'profile', calcOutputs.cornerPrice, calcOutputs.cornerQty, 10);

        // Clear calculator form
        document.getElementById('room_width').value = '';
        document.getElementById('room_length').value = '';
        document.getElementById('calc_panel_id').value = '';
        document.getElementById('calcResults').style.display = 'none';
        calcOutputs = null;

        showToast("Calculated panels and finishing profiles applied to list!");
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
</script>

<?php require_once 'footer.php'; ?>
