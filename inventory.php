<?php
// inventory.php
// Inventory management: search, add items, adjust stock levels

require_once 'auth.php';
require_once 'db.php';

// Secure inventory modifications (POST only)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_SESSION['user_role'] ?? '') !== 'admin') {
        $_SESSION['flash_msg'] = "Access denied: Only Administrators can modify materials or stock.";
        $_SESSION['flash_type'] = "danger";
        header("Location: inventory.php");
        exit;
    }
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_action'])) {
    enforce_csrf();

    if ($_POST['form_action'] === 'add_item') {
        $name = trim($_POST['name'] ?? '');
        $category = $_POST['category'] ?? 'panel';
        $type = trim($_POST['type'] ?? '');
        $width_mm = !empty($_POST['width_mm']) ? floatval($_POST['width_mm']) : null;
        $thickness_mm = !empty($_POST['thickness_mm']) ? floatval($_POST['thickness_mm']) : null;
        $length_ft = !empty($_POST['length_ft']) ? floatval($_POST['length_ft']) : null;
        $color = trim($_POST['color'] ?? '');
        $price = floatval($_POST['price'] ?? 0);
        $stock = intval($_POST['stock'] ?? 0);

        if (!empty($name) && $price >= 0) {
            try {
                $stmt = $db->prepare("INSERT INTO inventory (name, category, type, width_mm, thickness_mm, length_ft, color, price, stock) 
                                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $category, $type, $width_mm, $thickness_mm, $length_ft, $color, $price, $stock]);
                
                log_activity($db, 'add_inventory', "Added inventory item '{$name}' (Category: {$category}, Price: Rs. {$price}, Stock: {$stock})");

                $_SESSION['flash_msg'] = "Item successfully added to inventory.";
                $_SESSION['flash_type'] = "success";
            } catch (PDOException $e) {
                error_log("Error adding item: " . $e->getMessage());
                $_SESSION['flash_msg'] = "A database error occurred while adding the item.";
                $_SESSION['flash_type'] = "danger";
            }
        } else {
            $_SESSION['flash_msg'] = "Please provide a valid item name and price.";
            $_SESSION['flash_type'] = "danger";
        }
        header("Location: inventory.php");
        exit;
    }

    if ($_POST['form_action'] === 'adjust_stock') {
        $item_id = intval($_POST['item_id'] ?? 0);
        $new_stock = intval($_POST['new_stock'] ?? 0);

        if ($item_id > 0 && $new_stock >= 0) {
            try {
                $stmt_i = $db->prepare("SELECT name FROM inventory WHERE id = ?");
                $stmt_i->execute([$item_id]);
                $item_name = $stmt_i->fetchColumn() ?: "ID #$item_id";

                $stmt = $db->prepare("UPDATE inventory SET stock = ? WHERE id = ?");
                $stmt->execute([$new_stock, $item_id]);

                log_activity($db, 'adjust_stock', "Adjusted stock for '{$item_name}' to {$new_stock} units");
                
                $_SESSION['flash_msg'] = "Stock updated successfully.";
                $_SESSION['flash_type'] = "success";
            } catch (PDOException $e) {
                error_log("Error updating stock: " . $e->getMessage());
                $_SESSION['flash_msg'] = "A database error occurred while updating stock.";
                $_SESSION['flash_type'] = "danger";
            }
        }
        header("Location: inventory.php");
        exit;
    }

    if ($_POST['form_action'] === 'delete_item' && isset($_POST['id'])) {
        $item_id = intval($_POST['id']);
        try {
            $stmt_i = $db->prepare("SELECT name FROM inventory WHERE id = ?");
            $stmt_i->execute([$item_id]);
            $item_name = $stmt_i->fetchColumn() ?: "ID #$item_id";

            $stmt = $db->prepare("DELETE FROM inventory WHERE id = ?");
            $stmt->execute([$item_id]);

            log_activity($db, 'delete_inventory', "Deleted inventory item '{$item_name}'");
            
            $_SESSION['flash_msg'] = "Item deleted successfully.";
            $_SESSION['flash_type'] = "success";
        } catch (PDOException $e) {
            error_log("Error deleting item: " . $e->getMessage());
            $_SESSION['flash_msg'] = "A database error occurred while deleting the item.";
            $_SESSION['flash_type'] = "danger";
        }
        header("Location: inventory.php");
        exit;
    }
}

// Fetch all inventory items
try {
    $inventory_items = $db->query("SELECT * FROM inventory ORDER BY category ASC, name ASC")->fetchAll();
} catch (PDOException $e) {
    error_log("Query failed: " . $e->getMessage());
    die("An error occurred while loading inventory data.");
}

$is_admin = (($_SESSION['user_role'] ?? '') === 'admin');

require_once 'header.php';
?>

<div class="section-card">
    <div class="section-header">
        <h3 class="section-title">Ceiling Materials & Inventory</h3>
        <?php if ($is_admin): ?>
        <div>
            <button onclick="openModal('addItemModal')" class="btn btn-primary">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px;"><path d="M5 12h14"></path><path d="M12 5v14"></path></svg>
                Add New Item
            </button>
        </div>
        <?php endif; ?>
    </div>

    <!-- Search & Filter Controls -->
    <div style="display: flex; gap: 16px; margin-bottom: 24px; flex-wrap: wrap;">
        <div style="flex: 1; min-width: 250px;">
            <input type="text" id="searchInput" placeholder="Search by item name, color, type..." onkeyup="filterInventory()">
        </div>
        <div style="width: 200px;">
            <select id="categoryFilter" onchange="filterInventory()">
                <option value="">All Categories</option>
                <option value="panel">Ceiling Panels</option>
                <option value="profile">Finishing Profiles</option>
                <option value="accessory">Accessories</option>
            </select>
        </div>
    </div>

    <!-- Inventory Table -->
    <div style="overflow-x: auto;">
        <table class="app-table" id="inventoryTable">
            <thead>
                <tr>
                    <th>Category</th>
                    <th>Item Name</th>
                    <th>Specifications</th>
                    <th>Color</th>
                    <th style="text-align: right;">Unit Price (Rs.)</th>
                    <th style="text-align: center; width: 180px;">In Stock</th>
                    <?php if ($is_admin): ?>
                    <th style="text-align: right;">Actions</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($inventory_items)): ?>
                    <tr class="no-results-row">
                        <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 30px;">No inventory items found. Click "Add New Item" to begin.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($inventory_items as $item): ?>
                        <tr class="inventory-row" data-category="<?= htmlspecialchars($item['category']) ?>" data-search-text="<?= htmlspecialchars(strtolower($item['name'] . ' ' . $item['color'] . ' ' . $item['type'])) ?>">
                            <td data-label="Category">
                                <span class="badge" style="background-color: var(--secondary-brand); color: #ffffff;">
                                    <?= ucfirst($item['category']) ?>
                                </span>
                            </td>
                            <td data-label="Item Name" style="font-weight: 600;"><?= htmlspecialchars($item['name']) ?></td>
                            <td data-label="Specifications" style="font-size: 13px; color: var(--text-muted);">
                                <?php
                                $specs = [];
                                if ($item['width_mm']) $specs[] = $item['width_mm'] . "mm Width";
                                if ($item['thickness_mm']) $specs[] = $item['thickness_mm'] . "mm Thickness";
                                if ($item['length_ft']) $specs[] = $item['length_ft'] . "ft Length";
                                echo !empty($specs) ? implode(" | ", $specs) : 'N/A';
                                ?>
                            </td>
                            <td data-label="Color"><?= htmlspecialchars($item['color'] ?: 'Standard') ?></td>
                            <td data-label="Price" style="text-align: right; font-weight: 600; color: var(--primary-brand);">
                                Rs. <?= number_format($item['price'], 2) ?>
                            </td>
                            <td data-label="In Stock" style="text-align: center;">
                                <?php if ($is_admin): ?>
                                    <form action="inventory.php" method="POST" style="display: flex; align-items: center; justify-content: center; gap: 8px;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="form_action" value="adjust_stock">
                                        <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                        <input type="number" name="new_stock" value="<?= $item['stock'] ?>" min="0" style="width: 70px; padding: 4px 6px; text-align: center; border-radius: 0;">
                                        <button type="submit" class="btn btn-secondary btn-sm" style="font-weight: 700;">Update</button>
                                    </form>
                                <?php else: ?>
                                    <span style="font-weight: 600; color: var(--secondary-brand);"><?= $item['stock'] ?> units</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($is_admin): ?>
                            <td data-label="Actions" style="text-align: right;">
                                <form action="inventory.php" method="POST" style="display: inline-block; margin: 0;" onsubmit="return confirm('Are you sure you want to delete this inventory item? This cannot be undone.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="form_action" value="delete_item">
                                    <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm" style="font-weight: 700;">Delete</button>
                                </form>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                <tr id="noResults" style="display: none;">
                    <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 30px;">No matching products found.</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Add Item -->
<div id="addItemModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3 style="text-transform: uppercase;">New Inventory Item</h3>
            <button onclick="closeModal('addItemModal')" style="background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>
        
        <form action="inventory.php" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="add_item">
            
            <div class="form-group">
                <label for="name">Product Name *</label>
                <input type="text" id="name" name="name" required placeholder="e.g. i-Panel LITE White Wood">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="category">Category *</label>
                    <select id="category" name="category" onchange="toggleCategoryFields()">
                        <option value="panel">Ceiling Panel</option>
                        <option value="profile">Finishing Profile</option>
                        <option value="accessory">Accessory</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="type">Profile Type / Series</label>
                    <input type="text" id="type" name="type" placeholder="e.g. LITE, HEAVY B, Profile A">
                </div>
            </div>

            <!-- Specifications (Conditional display logic handles it smoothly) -->
            <div class="form-row" id="specificationsRow">
                <div class="form-group">
                    <label for="width_mm">Width (mm)</label>
                    <input type="number" id="width_mm" name="width_mm" step="0.1" placeholder="e.g. 200">
                </div>
                <div class="form-group">
                    <label for="thickness_mm">Thickness (mm)</label>
                    <input type="number" id="thickness_mm" name="thickness_mm" step="0.1" placeholder="e.g. 7.5">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="length_ft">Length (Feet)</label>
                    <input type="number" id="length_ft" name="length_ft" step="0.1" placeholder="e.g. 10">
                </div>
                <div class="form-group">
                    <label for="color">Color / Design</label>
                    <input type="text" id="color" name="color" placeholder="e.g. Teak Wood, Silver Line">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="price">Unit Price (Rs.) *</label>
                    <input type="number" id="price" name="price" step="0.01" min="0" required placeholder="0.00">
                </div>
                <div class="form-group">
                    <label for="stock">Opening Stock Qty</label>
                    <input type="number" id="stock" name="stock" min="0" value="0">
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 24px;">
                <button type="button" onclick="closeModal('addItemModal')" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px;"><path d="M5 12h14"></path><path d="M12 5v14"></path></svg>
                    Add Item
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    // Client side quick filter search
    function filterInventory() {
        const query = document.getElementById('searchInput').value.toLowerCase();
        const catFilter = document.getElementById('categoryFilter').value;
        const rows = document.querySelectorAll('.inventory-row');
        let visibleCount = 0;

        rows.forEach(row => {
            const searchText = row.getAttribute('data-search-text');
            const category = row.getAttribute('data-category');
            
            const matchSearch = searchText.includes(query);
            const matchCategory = !catFilter || category === catFilter;

            if (matchSearch && matchCategory) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });

        // Toggle no results row
        const noResults = document.getElementById('noResults');
        const noResultsRowDefault = document.querySelector('.no-results-row');
        
        if (visibleCount === 0) {
            noResults.style.display = '';
            if (noResultsRowDefault) noResultsRowDefault.style.display = 'none';
        } else {
            noResults.style.display = 'none';
        }
    }

    // Toggle fields based on selected product category
    function toggleCategoryFields() {
        const category = document.getElementById('category').value;
        const specsRow = document.getElementById('specificationsRow');
        
        if (category === 'accessory') {
            specsRow.style.opacity = '0.5';
            document.getElementById('width_mm').disabled = true;
            document.getElementById('thickness_mm').disabled = true;
            document.getElementById('width_mm').value = '';
            document.getElementById('thickness_mm').value = '';
        } else {
            specsRow.style.opacity = '1';
            document.getElementById('width_mm').disabled = false;
            document.getElementById('thickness_mm').disabled = false;
        }
    }

    // Auto open modal if URL has ?action=add
    <?php if (isset($_GET['action']) && $_GET['action'] === 'add'): ?>
        window.addEventListener('DOMContentLoaded', () => {
            openModal('addItemModal');
        });
    <?php endif; ?>
</script>

<?php require_once 'footer.php'; ?>
