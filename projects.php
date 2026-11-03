<?php
// projects.php
// Project tracker: list client sites, view details, list and manage client quotations

require_once 'auth.php';
require_once 'db.php';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_action'])) {
    enforce_csrf();

    if ($_POST['form_action'] === 'add_project') {
        $client_name = trim($_POST['client_name'] ?? '');
        $client_phone = trim($_POST['client_phone'] ?? '');
        $client_address = trim($_POST['client_address'] ?? '');
        $status = $_POST['status'] ?? 'Draft';

        if (!empty($client_name)) {
            try {
                $stmt = $db->prepare("INSERT INTO projects (client_name, client_phone, client_address, status) VALUES (?, ?, ?, ?)");
                $stmt->execute([$client_name, $client_phone, $client_address, $status]);
                $new_project_id = $db->lastInsertId();
                
                log_activity($db, 'add_project', "Created project for '{$client_name}' (Status: {$status})");

                $_SESSION['flash_msg'] = "Project created successfully.";
                $_SESSION['flash_type'] = "success";
                
                header("Location: projects.php?view_id=" . $new_project_id);
                exit;
            } catch (PDOException $e) {
                error_log("Error creating project: " . $e->getMessage());
                $_SESSION['flash_msg'] = "A database error occurred while creating the project.";
                $_SESSION['flash_type'] = "danger";
            }
        } else {
            $_SESSION['flash_msg'] = "Client name is required.";
            $_SESSION['flash_type'] = "danger";
        }
        header("Location: projects.php");
        exit;
    }

    if ($_POST['form_action'] === 'update_status') {
        $project_id = intval($_POST['project_id'] ?? 0);
        $status = $_POST['status'] ?? 'Draft';

        if ($project_id > 0) {
            try {
                $stmt = $db->prepare("UPDATE projects SET status = ? WHERE id = ?");
                $stmt->execute([$status, $project_id]);
                
                log_activity($db, 'update_project_status', "Updated project ID {$project_id} status to '{$status}'");

                $_SESSION['flash_msg'] = "Project status updated.";
                $_SESSION['flash_type'] = "success";
                
                header("Location: projects.php?view_id=" . $project_id);
                exit;
            } catch (PDOException $e) {
                error_log("Error updating status: " . $e->getMessage());
                $_SESSION['flash_msg'] = "A database error occurred while updating status.";
                $_SESSION['flash_type'] = "danger";
            }
        }
    }

    if ($_POST['form_action'] === 'delete_project' && isset($_POST['id'])) {
        if (($_SESSION['user_role'] ?? '') !== 'admin') {
            $_SESSION['flash_msg'] = "Access denied: Only Administrators can delete client projects.";
            $_SESSION['flash_type'] = "danger";
        } else {
            $project_id = intval($_POST['id']);
            try {
                $stmt_p = $db->prepare("SELECT client_name FROM projects WHERE id = ?");
                $stmt_p->execute([$project_id]);
                $p_name = $stmt_p->fetchColumn() ?: "ID #$project_id";

                $stmt = $db->prepare("DELETE FROM projects WHERE id = ?");
                $stmt->execute([$project_id]);
                
                log_activity($db, 'delete_project', "Deleted project '{$p_name}' and associated quotations");

                $_SESSION['flash_msg'] = "Project deleted successfully.";
                $_SESSION['flash_type'] = "success";
            } catch (PDOException $e) {
                error_log("Error deleting project: " . $e->getMessage());
                $_SESSION['flash_msg'] = "A database error occurred while deleting the project.";
                $_SESSION['flash_type'] = "danger";
            }
        }
        header("Location: projects.php");
        exit;
    }
}

// Fetch lists or individual records
$view_id = isset($_GET['view_id']) ? intval($_GET['view_id']) : 0;
$project_data = null;
$project_quotations = [];

if ($view_id > 0) {
    // View mode: fetch specific project details
    try {
        $stmt = $db->prepare("SELECT * FROM projects WHERE id = ?");
        $stmt->execute([$view_id]);
        $project_data = $stmt->fetch();
        
        if ($project_data) {
            // Fetch associated quotations
            $stmt = $db->prepare("SELECT * FROM quotations WHERE project_id = ? ORDER BY created_at DESC");
            $stmt->execute([$view_id]);
            $project_quotations = $stmt->fetchAll();
        } else {
            $_SESSION['flash_msg'] = "Project not found.";
            $_SESSION['flash_type'] = "danger";
            header("Location: projects.php");
            exit;
        }
    } catch (PDOException $e) {
        error_log("Project fetch failed: " . $e->getMessage());
        die("An error occurred while loading project details.");
    }
} else {
    // List mode: fetch all projects
    try {
        $projects = $db->query("SELECT * FROM projects ORDER BY created_at DESC")->fetchAll();
    } catch (PDOException $e) {
        error_log("Projects query failed: " . $e->getMessage());
        die("An error occurred while loading projects.");
    }
}

require_once 'header.php';
?>

<?php if ($view_id > 0 && $project_data): ?>
    <!-- ---------------------------------------------------- -->
    <!-- VIEW PROJECT DETAILS VIEW                           -->
    <!-- ---------------------------------------------------- -->
    
    <div style="margin-bottom: 20px;">
        <a href="projects.php" class="btn btn-secondary btn-sm" style="font-weight:700;">&larr; Back to Projects</a>
    </div>

    <div class="content-layout two-col">
        <!-- Project/Client Profile -->
        <div>
            <div class="section-card">
                <div class="section-header">
                    <h3 class="section-title">Client & Site details</h3>
                    <div>
                        <?php
                        $status_class = '';
                        switch ($project_data['status']) {
                            case 'Draft': $status_class = 'badge-draft'; break;
                            case 'Quotation Sent': $status_class = 'badge-sent'; break;
                            case 'Approved': $status_class = 'badge-approved'; break;
                            case 'In Progress': $status_class = 'badge-progress'; break;
                            case 'Completed': $status_class = 'badge-completed'; break;
                        }
                        ?>
                        <span class="badge <?= $status_class ?>" style="font-size:12px; padding: 6px 12px;"><?= $project_data['status'] ?></span>
                    </div>
                </div>

                <div style="margin-bottom: 24px;">
                    <div style="font-size: 11px; font-weight: 800; text-transform: uppercase; color: var(--text-muted); margin-bottom: 4px;">Client Name</div>
                    <div style="font-size: 18px; font-weight: 700; color: var(--secondary-brand);"><?= htmlspecialchars($project_data['client_name']) ?></div>
                </div>

                <div class="form-row" style="margin-bottom: 20px;">
                    <div>
                        <div style="font-size: 11px; font-weight: 800; text-transform: uppercase; color: var(--text-muted); margin-bottom: 4px;">Phone Number</div>
                        <div style="font-size: 15px; font-weight: 600;"><?= htmlspecialchars($project_data['client_phone'] ?: 'N/A') ?></div>
                    </div>
                    <div>
                        <div style="font-size: 11px; font-weight: 800; text-transform: uppercase; color: var(--text-muted); margin-bottom: 4px;">Created On</div>
                        <div style="font-size: 15px;"><?= date('F d, Y', strtotime($project_data['created_at'])) ?></div>
                    </div>
                </div>

                <div style="margin-bottom: 24px;">
                    <div style="font-size: 11px; font-weight: 800; text-transform: uppercase; color: var(--text-muted); margin-bottom: 4px;">Site Address</div>
                    <div style="font-size: 14px; background-color: var(--bg-main); padding: 12px; border-left: 3px solid var(--primary-brand);">
                        <?= nl2br(htmlspecialchars($project_data['client_address'] ?: 'No site address provided.')) ?>
                    </div>
                </div>

                <!-- Update Status Form -->
                <form action="projects.php" method="POST" style="border-top: 1px solid var(--border-color); padding-top: 20px;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="form_action" value="update_status">
                    <input type="hidden" name="project_id" value="<?= $project_data['id'] ?>">
                    
                    <div class="form-row" style="align-items: flex-end; margin-bottom: 0;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="status">Change Progress Status</label>
                            <select id="status" name="status">
                                <option value="Draft" <?= $project_data['status'] == 'Draft' ? 'selected' : '' ?>>Draft</option>
                                <option value="Quotation Sent" <?= $project_data['status'] == 'Quotation Sent' ? 'selected' : '' ?>>Quotation Sent</option>
                                <option value="Approved" <?= $project_data['status'] == 'Approved' ? 'selected' : '' ?>>Approved / Work Order</option>
                                <option value="In Progress" <?= $project_data['status'] == 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                                <option value="Completed" <?= $project_data['status'] == 'Completed' ? 'selected' : '' ?>>Completed</option>
                            </select>
                        </div>
                        <div>
                            <button type="submit" class="btn btn-secondary" style="height: 46px;">Update Status</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Project Estimates / Quotations -->
        <div>
            <div class="section-card">
                <div class="section-header">
                    <h3 class="section-title">Quotations & Billing</h3>
                    <a href="quotation.php?project_id=<?= $project_data['id'] ?>" class="btn btn-primary btn-sm">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 2px;"><line x1="12" x2="12" y1="5" y2="19"></line><line x1="5" x2="19" y1="12" y2="12"></line></svg>
                        Create Quote
                    </a>
                </div>

                <?php if (empty($project_quotations)): ?>
                    <div style="text-align: center; padding: 40px 20px; color: var(--text-muted);">
                        <p style="margin-bottom: 16px;">No quotations generated for this project yet.</p>
                        <a href="quotation.php?project_id=<?= $project_data['id'] ?>" class="btn btn-secondary">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px;"><line x1="12" x2="12" y1="5" y2="19"></line><line x1="5" x2="19" y1="12" y2="12"></line></svg>
                            Add First Quotation
                        </a>
                    </div>
                <?php else: ?>
                    <div style="display: flex; flex-direction: column; gap: 16px;">
                        <?php foreach ($project_quotations as $quote): 
                            // Calculate Quote Total Cost
                            $items_total = $db->query("SELECT SUM(total_price) FROM quotation_items WHERE quotation_id = " . intval($quote['id']))->fetchColumn() ?? 0;
                            $subtotal = $items_total + $quote['labor_cost'] + $quote['transport_cost'];
                            $discount = $subtotal * ($quote['discount_percentage'] / 100);
                            $tax = ($subtotal - $discount) * ($quote['tax_percentage'] / 100);
                            $grand_total = $subtotal - $discount + $tax;
                        ?>
                            <div class="card" style="padding: 16px; border: 1.5px solid var(--border-color); hover: border-color: var(--primary-brand);">
                                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px;">
                                    <div>
                                        <div style="font-weight: 700; color: var(--primary-brand);"><?= htmlspecialchars($quote['quotation_number']) ?></div>
                                        <div style="font-size: 11px; color: var(--text-muted);"><?= date('Y-m-d H:i', strtotime($quote['created_at'])) ?></div>
                                    </div>
                                    <span class="badge <?= ($quote['status'] == 'Accepted') ? 'badge-approved' : 'badge-draft' ?>">
                                        <?= $quote['status'] ?>
                                    </span>
                                </div>
                                
                                <div style="font-size: 14px; margin-bottom: 12px; font-weight: 600;">
                                    Total Value: Rs. <?= number_format($grand_total, 2) ?>
                                </div>
                                
                                <div style="display: flex; gap: 8px; justify-content: flex-end; border-top: 1px dashed var(--border-color); padding-top: 12px;">
                                    <a href="print_quotation.php?id=<?= $quote['id'] ?>" target="_blank" class="btn btn-secondary btn-sm" style="font-weight: 700;">Print / PDF</a>
                                    <a href="quotation.php?id=<?= $quote['id'] ?>" class="btn btn-primary btn-sm" style="font-weight: 700;">Edit Itemized</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

<?php else: ?>
    <!-- ---------------------------------------------------- -->
    <!-- LIST PROJECTS VIEW                                  -->
    <!-- ---------------------------------------------------- -->
    
    <div class="section-card">
        <div class="section-header">
            <h3 class="section-title">Client Projects Tracker</h3>
            <button onclick="openModal('addProjectModal')" class="btn btn-primary">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px;"><line x1="12" x2="12" y1="5" y2="19"></line><line x1="5" x2="19" y1="12" y2="12"></line></svg>
                Create New Project
            </button>
        </div>

        <!-- Search Bar -->
        <div style="margin-bottom: 20px;">
            <input type="text" id="projectSearch" placeholder="Search projects by client name..." onkeyup="filterProjects()">
        </div>

        <!-- Projects Table -->
        <div style="overflow-x: auto;">
            <table class="app-table" id="projectsTable">
                <thead>
                    <tr>
                        <th>Client Name</th>
                        <th>Phone Number</th>
                        <th>Site Address</th>
                        <th>Status</th>
                        <th>Date Registered</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($projects)): ?>
                        <tr class="no-projects-row">
                            <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 35px;">No active client projects. Click "Create New Project" to register a customer.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($projects as $proj): ?>
                            <tr class="project-row" data-client="<?= htmlspecialchars(strtolower($proj['client_name'])) ?>">
                                <td data-label="Client Name" style="font-weight: 600;"><?= htmlspecialchars($proj['client_name']) ?></td>
                                <td data-label="Phone Number"><?= htmlspecialchars($proj['client_phone'] ?: 'N/A') ?></td>
                                <td data-label="Site Address" style="max-width: 250px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: 13px; color: var(--text-muted);">
                                    <?= htmlspecialchars($proj['client_address']) ?>
                                </td>
                                <td data-label="Status">
                                    <?php
                                    $status_class = '';
                                    switch ($proj['status']) {
                                        case 'Draft': $status_class = 'badge-draft'; break;
                                        case 'Quotation Sent': $status_class = 'badge-sent'; break;
                                        case 'Approved': $status_class = 'badge-approved'; break;
                                        case 'In Progress': $status_class = 'badge-progress'; break;
                                        case 'Completed': $status_class = 'badge-completed'; break;
                                    }
                                    ?>
                                    <span class="badge <?= $status_class ?>"><?= $proj['status'] ?></span>
                                </td>
                                <td data-label="Date Registered"><?= date('Y-m-d', strtotime($proj['created_at'])) ?></td>
                                <td data-label="Actions" style="text-align: right; display: flex; justify-content: flex-end; gap: 8px;">
                                    <a href="projects.php?view_id=<?= $proj['id'] ?>" class="btn btn-secondary btn-sm" style="font-weight: 700;">View Details</a>
                                    <?php if ($_SESSION['user_role'] === 'admin'): ?>
                                    <form action="projects.php" method="POST" style="display: inline-block; margin: 0;" onsubmit="return confirm('Deleting this project will also delete all its associated quotations. Are you sure you want to proceed?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="form_action" value="delete_project">
                                        <input type="hidden" name="id" value="<?= $proj['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" style="font-weight: 700;">Delete</button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <tr id="noProjectsResults" style="display: none;">
                        <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 30px;">No matching client records found.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal: Add Project -->
    <div id="addProjectModal" class="modal-overlay">
        <div class="modal-container">
            <div class="modal-header">
                <h3 style="text-transform: uppercase;">Register Client Project</h3>
                <button onclick="closeModal('addProjectModal')" style="background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-muted);">&times;</button>
            </div>
            
            <form action="projects.php" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="form_action" value="add_project">
                
                <div class="form-group">
                    <label for="client_name">Customer / Client Name *</label>
                    <input type="text" id="client_name" name="client_name" required placeholder="e.g. Mr. Sunil Perera">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="client_phone">Phone Number</label>
                        <input type="tel" id="client_phone" name="client_phone" placeholder="e.g. 0771234567">
                    </div>
                    <div class="form-group">
                        <label for="status">Initial Status</label>
                        <select id="status" name="status">
                            <option value="Draft">Draft</option>
                            <option value="Quotation Sent">Quotation Sent</option>
                            <option value="Approved">Approved</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="client_address">Site Delivery & Installation Address</label>
                    <textarea id="client_address" name="client_address" rows="3" placeholder="e.g. No. 12, Galle Road, Baddegama"></textarea>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 24px;">
                    <button type="button" onclick="closeModal('addProjectModal')" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px;"><path d="M5 12h14"></path><path d="M12 5v14"></path></svg>
                        Create Project
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function filterProjects() {
            const query = document.getElementById('projectSearch').value.toLowerCase();
            const rows = document.querySelectorAll('.project-row');
            let visibleCount = 0;

            rows.forEach(row => {
                const client = row.getAttribute('data-client');
                if (client.includes(query)) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });

            const noResults = document.getElementById('noProjectsResults');
            const noProjectsDefault = document.querySelector('.no-projects-row');
            
            if (visibleCount === 0) {
                noResults.style.display = '';
                if (noProjectsDefault) noProjectsDefault.style.display = 'none';
            } else {
                noResults.style.display = 'none';
            }
        }

        // Auto-open modal if URL specifies ?action=new
        <?php if (isset($_GET['action']) && $_GET['action'] === 'new'): ?>
            window.addEventListener('DOMContentLoaded', () => {
                openModal('addProjectModal');
            });
        <?php endif; ?>
    </script>
<?php endif; ?>

<?php require_once 'footer.php'; ?>
