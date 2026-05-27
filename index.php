<link rel="icon" type="image/svg+xml" href="/logo.svg">
<?php
session_start();

// ==========================================
// 🔗 ตั้งค่าการเชื่อมต่อ GOOGLE SHEETS
// ==========================================
// นำ URL เว็บแอปที่ได้จาก Google Apps Script มาวางที่นี่
$google_sheet_url = 'https://script.google.com/macros/s/AKfycbxa4RqsgbNjEdxDMM3hVUMwizG2piTB29wCjKA3q1jJdTPGED1PkiN18erEElYxn-I/exec'; 

$product_file = 'products.json';
$history_file = 'history.json';
$user_file = 'users.json';
$upload_dir = 'uploads/';

if (!is_dir($upload_dir)) { mkdir($upload_dir, 0755, true); }

// ฟังก์ชันจัดการข้อมูล
function get_data($file) { return json_decode(file_get_contents($file), true); }
function save_data($file, $data) { file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); }

// ฟังก์ชันส่งข้อมูลเข้า Google Sheets แบบ Real-time
function sync_to_google_sheet($action, $id, $name, $qty, $user, $reason) {
    global $google_sheet_url;
    if (empty($google_sheet_url) || strpos($google_sheet_url, 'วาง_URL') !== false) { return false; }
    
    $data = [
        "date" => date('Y-m-d H:i:s'),
        "action" => $action,
        "id" => $id,
        "name" => $name,
        "qty" => (int)$qty,
        "user" => $user,
        "reason" => $reason
    ];
    
    $options = [
        'http' => [
            'header'  => "Content-type: application/json\r\n",
            'method'  => 'POST',
            'content' => json_encode($data),
            'timeout' => 5
        ]
    ];
    $context  = stream_context_create($options);
    @file_get_contents($google_sheet_url, false, $context);
}

// สร้างไฟล์เริ่มต้นถ้ายังไม่มี
if (!file_exists($product_file)) {
    $initial_products = [
        ["id" => "IT-001", "name" => "Mouse Wireless Logitech", "qty" => 15, "unit" => "ชิ้น", "price" => 450, "img" => "https://images.unsplash.com/photo-1615663245857-ac93bb7c39e7?w=500"],
        ["id" => "IT-002", "name" => "สาย LAN Cat6 30M", "qty" => 5, "unit" => "กล่อง", "price" => 350, "img" => "https://images.unsplash.com/photo-1544244015-0df4b3ffc6b0?w=500"]
    ];
    save_data($product_file, $initial_products);
}
if (!file_exists($history_file)) { save_data($history_file, []); }
if (!file_exists($user_file)) {
    $initial_users = [
        ["username" => "admin", "name" => "Admin System", "password" => "1234", "role" => "admin"],
        ["username" => "itstock", "name" => "ITStock User", "password" => "1234", "role" => "user"]
    ];
    save_data($user_file, $initial_users);
}

// โหลดข้อมูลผู้ใช้ทั้งหมด
$account_users = get_data($user_file);

// ฟังก์ชันอัปโหลดรูปภาพ
function handle_image_upload($file_input_name) {
    global $upload_dir;
    if (isset($_FILES[$file_input_name]) && $_FILES[$file_input_name]['error'] === UPLOAD_ERR_OK) {
        $file_tmp = $_FILES[$file_input_name]['tmp_name'];
        $file_name = $_FILES[$file_input_name]['name'];
        $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            $new_file_name = time() . '_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($file_tmp, $upload_dir . $new_file_name)) { return $upload_dir . $new_file_name; }
        }
    }
    return null;
}

// 🔐 ระบบล็อกอิน
$error = '';
if (isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    
    foreach ($account_users as $u) {
        if (strtolower($u['username']) === strtolower($username) && $u['password'] === $password) {
            $_SESSION['user'] = $u['username']; 
            $_SESSION['display_name'] = $u['name'] ?? $u['username'];
            $_SESSION['role'] = $u['role']; 
            header('Location: ' . $_SERVER['PHP_SELF']); 
            exit;
        }
    }
    $error = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง!';
}

if (isset($_GET['action']) && $_GET['action'] == 'logout') { session_destroy(); header('Location: ' . $_SERVER['PHP_SELF']); exit; }

$is_logged_in = isset($_SESSION['user']);

if ($is_logged_in) {
    $products = get_data($product_file);
    $history = get_data($history_file);
    $role = $_SESSION['role'];
    $current_user = $_SESSION['user'];

    // Action: อัปเดตโปรไฟล์ตัวเอง
    if (isset($_POST['update_profile'])) {
        $new_password = trim($_POST['profile_password']);
        if (!empty($new_password)) {
            foreach ($account_users as &$u) {
                if ($u['username'] === $current_user) {
                    $u['password'] = $new_password;
                    break;
                }
            }
            save_data($user_file, $account_users);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?status=profile_updated'); exit;
        }
    }

    // 🌟 Action Admin: เพิ่มผู้ใช้งานใหม่
    if (isset($_POST['add_user']) && $role === 'admin') {
        $new_username = trim($_POST['new_username']);
        $new_name = trim($_POST['new_name']);
        $new_password = trim($_POST['new_password']);
        $new_role = $_POST['new_role'];

        $exists = false;
        foreach ($account_users as $u) {
            if (strtolower($u['username']) === strtolower($new_username)) { $exists = true; break; }
        }

        if (!$exists && !empty($new_username) && !empty($new_password)) {
            $account_users[] = [
                "username" => $new_username,
                "name" => $new_name ?: $new_username,
                "password" => $new_password,
                "role" => $new_role
            ];
            save_data($user_file, $account_users);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?status=user_added'); exit;
        } else {
            header('Location: ' . $_SERVER['PHP_SELF'] . '?status=user_exists'); exit;
        }
    }

    // 🌟 Action Admin: ลบผู้ใช้งาน
    if (isset($_GET['action']) && $_GET['action'] == 'delete_user' && isset($_GET['username']) && $role === 'admin') {
        $target_user = $_GET['username'];
        if ($target_user !== $current_user) {
            $account_users = array_filter($account_users, function($u) use ($target_user) {
                return $u['username'] !== $target_user;
            });
            save_data($user_file, array_values($account_users));
        }
        header('Location: ' . $_SERVER['PHP_SELF'] . '?status=user_deleted'); exit;
    }

    // Action Admin: เคลียร์ค้างยืมรายบุคคล
    if (isset($_GET['action']) && $_GET['action'] == 'clear_user_borrow' && isset($_GET['username']) && $role === 'admin') {
        $target_user = $_GET['username'];
        $history = array_filter($history, function($h) use ($target_user) {
            return $h['user'] !== $target_user; 
        });
        save_data($history_file, array_values($history));
        header('Location: ' . $_SERVER['PHP_SELF'] . '?status=clear_borrow_success'); exit;
    }

    // Action Admin: เคลียร์จำนวนของในคลังทั้งหมดให้เป็น 0
    if (isset($_GET['action']) && $_GET['action'] == 'clear_all_stock' && $role === 'admin') {
        foreach ($products as &$p) { $p['qty'] = 0; }
        save_data($product_file, $products);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?status=stock_cleared'); exit;
    }

    // Action: ยืมสินค้า
    if (isset($_POST['action_borrow'])) {
        $p_id = $_POST['p_id']; $reason = $_POST['reason']; $borrow_qty = (int)$_POST['qty'];
        if ($borrow_qty > 0) {
            foreach ($products as &$p) {
                if ($p['id'] === $p_id && $p['qty'] >= $borrow_qty) {
                    $p['qty'] -= $borrow_qty;
                    $history[] = ["date" => date('Y-m-d H:i:s'), "user" => $current_user, "action" => "ยืม", "id" => $p['id'], "name" => $p['name'], "qty" => $borrow_qty, "reason" => $reason];
                    save_data($product_file, $products); save_data($history_file, $history);
                    
                    // Sync Google Sheet
                    sync_to_google_sheet("⬇️ ยืมอุปกรณ์", $p['id'], $p['name'], $borrow_qty, $current_user, $reason);
                    break;
                }
            }
        }
        header('Location: ' . $_SERVER['PHP_SELF'] . '?status=borrow_success'); exit;
    }

    // 🔒 Action: คืนสินค้า
    if (isset($_POST['action_return'])) {
        $p_id = $_POST['p_id']; 
        $return_qty = (int)$_POST['qty'];
        
        $my_borrowed_balance = 0;
        foreach ($history as $h) {
            if ($h['user'] === $current_user && $h['id'] === $p_id) {
                $h_qty = isset($h['qty']) ? (int)$h['qty'] : 1;
                if ($h['action'] === 'ยืม') { $my_borrowed_balance += $h_qty; }
                if ($h['action'] === 'คืน') { $my_borrowed_balance -= $h_qty; }
            }
        }

        if ($return_qty > 0) {
            if ($role !== 'admin') {
                if ($my_borrowed_balance <= 0) {
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?status=error_not_borrowed'); exit;
                }
                if ($return_qty > $my_borrowed_balance) {
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?status=error_return_over&max=' . $my_borrowed_balance); exit;
                }
            }

            foreach ($products as &$p) {
                if ($p['id'] === $p_id) {
                    $p['qty'] += $return_qty;
                    $history[] = ["date" => date('Y-m-d H:i:s'), "user" => $current_user, "action" => "คืน", "id" => $p['id'], "name" => $p['name'], "qty" => $return_qty, "reason" => "-"];
                    save_data($product_file, $products); save_data($history_file, $history);
                    
                    // Sync Google Sheet
                    sync_to_google_sheet("⬆️ คืนอุปกรณ์", $p['id'], $p['name'], $return_qty, $current_user, "ส่งคืนคลังระบบ");
                    break;
                }
            }
            header('Location: ' . $_SERVER['PHP_SELF'] . '?status=return_success'); exit;
        }
        header('Location: ' . $_SERVER['PHP_SELF']); exit;
    }

    // Action Admin: เพิ่มสินค้า
    if (isset($_POST['add_product']) && $role === 'admin') {
        $uploaded_img = handle_image_upload('img_file');
        $initial_qty = (int)$_POST['qty'];
        $products[] = [
            "id" => $_POST['id'], "name" => $_POST['name'], "qty" => $initial_qty, "unit" => $_POST['unit'],
            "price" => (float)$_POST['price'],
            "img" => $uploaded_img ?: 'https://images.unsplash.com/photo-1588508065123-287b28e013da?w=500'
        ];
        save_data($product_file, $products);
        
        // Sync Google Sheet (เมื่อแอดมินเพิ่มสต็อกของใหม่)
        sync_to_google_sheet("📦 เพิ่มของใหม่", $_POST['id'], $_POST['name'], $initial_qty, $current_user, "แอดมินนำเข้าของใหม่");
        
        header('Location: ' . $_SERVER['PHP_SELF'] . '?status=product_added'); exit;
    }
    
    // Action Admin: ลบสินค้า
    if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id']) && $role === 'admin') {
        $products = array_filter($products, function($p) { return $p['id'] !== $_GET['id']; });
        save_data($product_file, array_values($products)); header('Location: ' . $_SERVER['PHP_SELF'] . '?status=product_deleted'); exit;
    }
    
    // Action Admin: แก้ไขสินค้า
    if (isset($_POST['edit_product']) && $role === 'admin') {
        $uploaded_img = handle_image_upload('img_file');
        $new_qty = (int)$_POST['qty'];
        foreach ($products as &$p) {
            if ($p['id'] === $_POST['old_id']) {
                $old_qty = $p['qty'];
                $p['id'] = $_POST['id']; $p['name'] = $_POST['name']; $p['qty'] = $new_qty; 
                $p['unit'] = $_POST['unit']; $p['price'] = (float)$_POST['price'];
                if ($uploaded_img) { $p['img'] = $uploaded_img; }
                
                // ถ้ายอดใหม่มากกว่ายอดเดิม แสดงว่าแอดมินเติมของเข้าคลัง
                if ($new_qty > $old_qty) {
                    $diff_qty = $new_qty - $old_qty;
                    sync_to_google_sheet("📦 เติมสต็อกสินค้า", $p['id'], $p['name'], $diff_qty, $current_user, "แอดมินแก้ไขปรับปรุงเพิ่มยอดคลัง");
                }
                break;
            }
        }
        save_data($product_file, $products); header('Location: ' . $_SERVER['PHP_SELF'] . '?status=product_updated'); exit;
    }

    // คำนวณสถิติทั่วไป
    $low_stock_items = []; $total_stock_qty = 0; $total_stock_value = 0;
    foreach ($products as $p) { 
        if ($p['qty'] <= 2) { $low_stock_items[] = $p; } 
        $total_stock_qty += $p['qty'];
        $price = isset($p['price']) ? $p['price'] : 0;
        $total_stock_value += ($p['qty'] * $price);
    }

    $outstanding_borrows = []; $monthly_report = []; 
    foreach ($history as $h) {
        $u = $h['user']; $p_id = $h['id']; $h_qty = isset($h['qty']) ? (int)$h['qty'] : 1;
        if (!isset($outstanding_borrows[$u][$p_id])) { $outstanding_borrows[$u][$p_id] = ["name" => $h['name'], "qty" => 0]; }
        if ($h['action'] === 'ยืม') { $outstanding_borrows[$u][$p_id]['qty'] += $h_qty; }
        if ($h['action'] === 'คืน') { $outstanding_borrows[$u][$p_id]['qty'] -= $h_qty; }

        if (!empty($h['date'])) {
            $month_key = date('Y-m', strtotime($h['date']));
            if (!isset($monthly_report[$month_key])) { $monthly_report[$month_key] = ["borrow_count" => 0, "return_count" => 0]; }
            if ($h['action'] === 'ยืม') { $monthly_report[$month_key]['borrow_count'] += $h_qty; }
            if ($h['action'] === 'คืน') { $monthly_report[$month_key]['return_count'] += $h_qty; }
        }
    }
    krsort($monthly_report);
    foreach ($outstanding_borrows as $u => $items) {
        foreach ($items as $p_id => $data) { if ($data['qty'] <= 0) { unset($outstanding_borrows[$u][$p_id]); } }
        if (empty($outstanding_borrows[$u])) { unset($outstanding_borrows[$u]); }
    }
}
?>
<!DOCTYPE html>
<html lang="th" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>ระบบจัดการคลังอุปกรณ์ IT</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Chakra Petch', sans-serif; background-color: #0f172a; -webkit-tap-highlight-color: transparent; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
    </style>
</head>
<body class="text-slate-100 min-h-screen flex flex-col antialiased">

    <?php if (!$is_logged_in): ?>
    <div class="flex-grow flex items-center justify-center p-4">
        <div class="bg-slate-800 border border-slate-700 p-6 sm:p-8 rounded-2xl shadow-2xl w-full max-w-md">
            <div class="text-center mb-6">
                <h1 class="text-2xl font-bold tracking-wider text-cyan-400">IT STOCK LOGIN</h1>
            </div>
            <?php if($error): ?><div class="bg-red-500/10 border border-red-500 text-red-400 p-3 rounded-xl text-xs mb-4 text-center"><?php echo $error; ?></div><?php endif; ?>
            <form action="" method="POST" class="space-y-4">
                <div><label class="block text-xs text-slate-400 mb-2">Username</label><input type="text" name="username" required class="w-full bg-slate-900 border border-slate-700 rounded-xl px-4 py-3 text-slate-200 focus:outline-none focus:border-cyan-500"></div>
                <div><label class="block text-xs text-slate-400 mb-2">Password</label><input type="password" name="password" required class="w-full bg-slate-900 border border-slate-700 rounded-xl px-4 py-3 text-slate-200 focus:outline-none focus:border-cyan-500"></div>
                <button type="submit" name="login" class="w-full bg-cyan-600 hover:bg-cyan-500 text-white font-semibold py-3.5 rounded-xl transition">เข้าสู่ระบบ</button>
            </form>
        </div>
    </div>
    <?php else: ?>
    
    <header class="bg-slate-800/90 border-b border-slate-700 sticky top-0 z-40 px-4">
        <div class="max-w-7xl mx-auto h-16 flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <button onclick="toggleSidebar()" class="text-slate-200 p-2 rounded-xl bg-slate-700/50">☰</button>
                <span class="text-lg font-bold text-cyan-400">IT STOCK</span>
            </div>
            <span class="px-2.5 py-1 text-xs rounded-lg bg-cyan-500/10 text-cyan-400">👤 <?php echo htmlspecialchars($_SESSION['display_name'] ?? $current_user); ?> (<?php echo strtoupper($role); ?>)</span>
        </div>
    </header>

    <div id="sidebarMenu" class="fixed inset-0 z-50 transform -translate-x-full transition-transform duration-300">
        <div onclick="toggleSidebar()" class="absolute inset-0 bg-black/60"></div>
        <div class="absolute inset-y-0 left-0 w-72 bg-slate-800 flex flex-col justify-between shadow-2xl">
            <div>
                <div class="p-4 border-b border-slate-700 flex justify-between bg-slate-900/40">
                    <span class="font-bold text-cyan-400">เมนูจัดการระบบ</span>
                    <button onclick="toggleSidebar()" class="text-slate-400 text-xl">&times;</button>
                </div>
                <nav class="p-4 space-y-1.5">
                    <button onclick="closeSidebar();" class="w-full text-left bg-slate-700/40 text-slate-200 px-4 py-3 rounded-xl text-sm">📦 คลังอุปกรณ์ IT</button>
                    <button onclick="toggleModal('historyModal'); closeSidebar();" class="w-full text-left text-slate-300 px-4 py-3 rounded-xl text-sm">📜 ประวัติ & สรุปรายเดือน</button>
                    <?php if ($role === 'admin'): ?>
                    <button onclick="toggleModal('summaryModal'); closeSidebar();" class="w-full text-left text-amber-400 px-4 py-3 rounded-xl text-sm">📊 สรุปรายงานสต็อก (แอดมิน)</button>
                    <button onclick="toggleModal('userManageModal'); closeSidebar();" class="w-full text-left text-emerald-400 px-4 py-3 rounded-xl text-sm font-semibold">👥 จัดการผู้ใช้งาน (แอดมิน)</button>
                    <hr class="border-slate-700 my-2">
                    <button onclick="confirmClearAllStock(); closeSidebar();" class="w-full text-left text-red-400 hover:bg-red-500/10 px-4 py-3 rounded-xl text-sm">⚠️ ล้างของในคลังทั้งหมดเป็น 0</button>
                    <?php endif; ?>
                    <hr class="border-slate-700 my-2">
                    <button onclick="toggleModal('profileModal'); closeSidebar();" class="w-full text-left text-purple-400 px-4 py-3 rounded-xl text-sm">⚙️ ข้อมูลผู้ใช้ & เปลี่ยนรหัส</button>
                </nav>
            </div>
            <div class="p-4"><a href="?action=logout" class="block text-center bg-red-500/10 text-red-400 py-2.5 rounded-xl text-sm">🚪 ออกจากระบบ</a></div>
        </div>
    </div>

    <main class="max-w-7xl mx-auto px-4 py-6 flex-grow w-full">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-xl font-bold text-slate-100">คลังอุปกรณ์ IT</h2>
            <div class="flex items-center space-x-2">
                <?php if ($role === 'admin'): ?>
                    <button onclick="openAddModal()" class="bg-emerald-600 text-white text-xs px-4 py-2.5 rounded-xl">➕ เพิ่มอุปกรณ์</button>
                <?php endif; ?>
            </div>
        </div>

        <div class="mb-6 relative">
            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none"><span class="text-slate-400">🔍</span></div>
            <input type="text" id="searchInput" oninput="searchProducts()" placeholder="ค้นหาชื่ออุปกรณ์ หรือ รหัสสินค้า..." class="w-full bg-slate-800 border border-slate-700 rounded-xl pl-12 pr-4 py-3.5 text-slate-200 focus:outline-none focus:border-cyan-500 text-sm">
        </div>

        <div id="noSearchItem" class="hidden text-center py-12 bg-slate-800/40 border border-dashed border-slate-700 rounded-2xl mb-6">
            <p class="text-slate-400 text-sm">❌ ไม่พบอุปกรณ์ที่ตรงกับเงื่อนหาการค้นหาของคุณ</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
            <?php foreach ($products as $p): 
                $is_out_of_stock = ($p['qty'] <= 0); // ตรวจสอบว่าของหมดคลังหรือไม่
            ?>
            <div class="product-card border rounded-2xl overflow-hidden shadow-xl flex flex-col justify-between transition-all duration-300 <?php echo $is_out_of_stock ? 'bg-slate-900/70 border-red-900/50 grayscale-[20%]' : 'bg-slate-800 border-slate-700'; ?>" data-name="<?php echo htmlspecialchars($p['name']); ?>" data-id="<?php echo htmlspecialchars($p['id']); ?>">
                <div class="relative h-44 bg-slate-900 flex items-center justify-center overflow-hidden">
                    <img src="<?php echo htmlspecialchars($p['img']); ?>" class="w-full h-full object-cover">
                    <span class="absolute top-3 left-3 bg-slate-900/80 text-cyan-400 text-[10px] px-2 py-0.5 rounded"><?php echo htmlspecialchars($p['id']); ?></span>
                    
                    <span class="absolute top-3 right-3 text-[10px] px-2 py-0.5 rounded font-bold <?php echo !$is_out_of_stock ? 'bg-emerald-500/10 text-emerald-400' : 'bg-red-500/20 text-red-400 animate-pulse border border-red-500/30'; ?>">
                        <?php echo !$is_out_of_stock ? 'เหลือ: '.$p['qty'].' '.$p['unit'] : '🔴 ของหมดชั่วคราว'; ?>
                    </span>
                    
                    <?php if ($is_out_of_stock): ?>
                    <div class="absolute inset-0 bg-red-950/40 flex items-center justify-center backdrop-blur-[1px]">
                        <span class="bg-red-600 text-white font-bold text-xs px-3 py-1.5 rounded-full shadow-lg border border-red-400">🔴 OUT OF STOCK</span>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="p-4 flex-grow flex flex-col justify-between">
                    <div>
                        <h3 class="font-semibold text-base line-clamp-1 <?php echo $is_out_of_stock ? 'text-slate-400 line-through' : 'text-slate-100'; ?>"><?php echo htmlspecialchars($p['name']); ?></h3>
                        <div class="flex justify-between mt-1">
                            <?php if($is_out_of_stock): ?>
                                <span class="text-xs text-red-400 font-semibold bg-red-500/10 px-2 py-0.5 rounded">⚠️ กรุณาติดต่อแอดมินเพื่อเติมของ</span>
                            <?php else: ?>
                                <span class="text-xs text-slate-400">คงเหลือ: <b class="text-emerald-400"><?php echo $p['qty']; ?></b> <?php echo htmlspecialchars($p['unit']); ?></span>
                            <?php endif; ?>
                            <?php if(isset($p['price'])): ?><span class="text-xs text-amber-400 font-mono">฿<?php echo number_format($p['price'], 2); ?></span><?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-2 mt-4">
                        <button onclick="borrowItem('<?php echo $p['id']; ?>', '<?php echo htmlspecialchars($p['name'], ENT_QUOTES); ?>', <?php echo $p['qty']; ?>)" 
                                class="text-xs py-2.5 rounded-xl font-medium transition-all <?php echo $is_out_of_stock ? 'bg-slate-800 text-slate-600 cursor-not-allowed border border-slate-700' : 'bg-cyan-600 hover:bg-cyan-500 text-white shadow-md shadow-cyan-900/20'; ?>" 
                                <?php echo $is_out_of_stock ? 'disabled' : ''; ?>>
                            <?php echo $is_out_of_stock ? '❌ ของหมด' : '⬇️ ยืม'; ?>
                        </button>
                        <button onclick="returnItem('<?php echo $p['id']; ?>', '<?php echo htmlspecialchars($p['name'], ENT_QUOTES); ?>')" class="bg-slate-700 hover:bg-slate-600 text-slate-200 text-xs py-2.5 rounded-xl">⬆️ คืน</button>
                    </div>
                    
                    <?php if ($role === 'admin'): ?>
                        <div class="grid grid-cols-2 gap-2 pt-2 mt-2 border-t border-slate-700/60">
                            <button onclick='openEditModal(<?php echo json_encode($p, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' class="text-[11px] bg-purple-500/10 text-purple-400 py-1.5 rounded-lg hover:bg-purple-500/20 transition">🔧 แก้ไข/เติมสต็อก</button>
                            <button onclick="confirmDelete('<?php echo $p['id']; ?>')" class="text-[11px] bg-red-500/10 text-red-400 py-1.5 rounded-lg hover:bg-red-500/20 transition">🗑️ ลบ</button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </main>

    <?php if ($role === 'admin'): ?>
    <div id="userManageModal" class="fixed inset-0 z-50 hidden bg-black/70 flex items-center justify-center p-4">
        <div class="bg-slate-800 w-full max-w-2xl h-[85vh] rounded-2xl flex flex-col shadow-2xl overflow-hidden border border-slate-700">
            <div class="p-4 border-b border-slate-700 flex justify-between">
                <h3 class="font-bold text-slate-100">👥 จัดการผู้ใช้งานในระบบ</h3>
                <button onclick="toggleModal('userManageModal')" class="text-slate-400 text-xl">&times;</button>
            </div>
            <div class="p-4 overflow-y-auto space-y-6 no-scrollbar flex-grow">
                <div class="bg-slate-900/50 border border-slate-700 p-4 rounded-xl">
                    <h4 class="text-xs font-bold text-cyan-400 uppercase mb-3">➕ เพิ่มผู้ใช้งานใหม่</h4>
                    <form method="POST" class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                        <div>
                            <label class="block text-slate-400 mb-1">Username (ใช้ล็อกอิน)</label>
                            <input type="text" name="new_username" required placeholder="เช่น com_staff" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-slate-200 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-slate-400 mb-1">ชื่อแสดงผล (Display Name)</label>
                            <input type="text" name="new_name" placeholder="เช่น เจ้าหน้าที่ฝ่าย IT" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-slate-200 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-slate-400 mb-1">Password</label>
                            <input type="text" name="new_password" required placeholder="รหัสผ่านเข้าใช้งาน" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-slate-200 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-slate-400 mb-1">สิทธิ์การใช้งาน (Role)</label>
                            <select name="new_role" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-slate-200 focus:outline-none">
                                <option value="user">User (ยืม-คืนได้อย่างเดียว)</option>
                                <option value="admin">Admin (จัดการระบบทั้งหมดได้)</option>
                            </select>
                        </div>
                        <div class="sm:col-span-2 pt-2">
                            <button type="submit" name="add_user" class="w-full bg-emerald-600 hover:bg-emerald-500 text-white font-semibold py-2 rounded-lg transition">สร้างผู้ใช้งานใหม่</button>
                        </div>
                    </form>
                </div>

                <div>
                    <h4 class="text-xs font-bold text-slate-400 uppercase mb-2">รายชื่อผู้ใช้งานทั้งหมด</h4>
                    <div class="bg-slate-950/60 border border-slate-700 rounded-xl overflow-x-auto">
                        <table class="w-full text-left text-xs whitespace-nowrap">
                            <thead class="bg-slate-900/80 text-slate-400 border-b border-slate-800">
                                <tr>
                                    <th class="px-4 py-2.5">Username</th>
                                    <th class="px-4 py-2.5">ชื่อแสดงผล</th>
                                    <th class="px-4 py-2.5">รหัสผ่าน</th>
                                    <th class="px-4 py-2.5 text-center">สิทธิ์</th>
                                    <th class="px-4 py-2.5 text-center">การกระทำ</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800 text-slate-300">
                                <?php foreach ($account_users as $u): ?>
                                <tr class="hover:bg-slate-900/40">
                                    <td class="px-4 py-2.5 font-mono text-cyan-400"><?php echo htmlspecialchars($u['username']); ?></td>
                                    <td class="px-4 py-2.5"><?php echo htmlspecialchars($u['name'] ?? $u['username']); ?></td>
                                    <td class="px-4 py-2.5 font-mono text-slate-400"><?php echo htmlspecialchars($u['password']); ?></td>
                                    <td class="px-4 py-2.5 text-center">
                                        <span class="px-2 py-0.5 rounded text-[10px] <?php echo $u['role'] === 'admin' ? 'bg-amber-500/10 text-amber-400' : 'bg-blue-500/10 text-blue-400'; ?>">
                                            <?php echo strtoupper($u['role']); ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-2.5 text-center">
                                        <?php if ($u['username'] !== $current_user): ?>
                                            <button onclick="confirmDeleteUser('<?php echo htmlspecialchars($u['username'], ENT_QUOTES); ?>')" class="bg-red-500/10 text-red-400 px-2 py-1 rounded hover:bg-red-500/20 text-[11px]">ลบ</button>
                                        <?php else: ?>
                                            <span class="text-slate-500 text-[11px]">คุณกำลังใช้งาน</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($role === 'admin'): ?>
    <div id="summaryModal" class="fixed inset-0 z-50 hidden bg-black/70 flex items-center justify-center p-4">
        <div class="bg-slate-800 w-full max-w-3xl h-[85vh] rounded-2xl flex flex-col shadow-2xl overflow-hidden border border-slate-700">
            <div class="p-4 border-b border-slate-700 flex justify-between"><h3 class="font-bold text-slate-100">📊 สรุปรายงานข้อมูลสต็อก</h3><button onclick="toggleModal('summaryModal')" class="text-slate-400">&times;</button></div>
            <div class="p-4 overflow-y-auto space-y-5 no-scrollbar">
                <div class="bg-gradient-to-r from-slate-900 to-slate-800 border border-slate-700 p-4 rounded-xl">
                    <h4 class="text-xs font-bold text-emerald-400 uppercase mb-3">💰 สรุปมูลค่าและจำนวนอุปกรณ์ในคลังปัจจุบัน (ไม่รวมของค้างยืม)</h4>
                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div class="bg-slate-950/50 p-3 rounded-lg text-center">
                            <div class="text-[10px] text-slate-400">จำนวนอุปกรณ์รวม</div>
                            <div class="text-xl font-bold text-cyan-400"><?php echo number_format($total_stock_qty); ?> <span class="text-xs font-normal">ชิ้น</span></div>
                        </div>
                        <div class="bg-slate-950/50 p-3 rounded-lg text-center">
                            <div class="text-[10px] text-slate-400">มูลค่ารวมทั้งสิ้น</div>
                            <div class="text-xl font-bold text-amber-400">฿<?php echo number_format($total_stock_value, 2); ?></div>
                        </div>
                    </div>
                    <div class="bg-slate-950/80 rounded-lg overflow-x-auto">
                        <table class="w-full text-left text-xs whitespace-nowrap">
                            <thead class="text-slate-400 border-b border-slate-800">
                                <tr>
                                    <th class="px-3 py-2">สินค้า</th>
                                    <th class="px-3 py-2 text-right">จำนวน</th>
                                    <th class="px-3 py-2 text-right">ราคา/ชิ้น</th>
                                    <th class="px-3 py-2 text-right text-amber-400">ราคารวม</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800 text-slate-300">
                                <?php foreach ($products as $p): $price = $p['price'] ?? 0; ?>
                                <tr>
                                    <td class="px-3 py-2 flex flex-col"><span class="font-mono text-[9px] text-cyan-500"><?php echo htmlspecialchars($p['id']); ?></span><?php echo htmlspecialchars($p['name']); ?></td>
                                    <td class="px-3 py-2 text-right <?php echo $p['qty'] <= 0 ? 'text-red-400 font-bold' : ''; ?>"><?php echo $p['qty'] > 0 ? $p['qty'] : 'หมด'; ?></td>
                                    <td class="px-3 py-2 text-right">฿<?php echo number_format($price, 2); ?></td>
                                    <td class="px-3 py-2 text-right font-bold text-amber-400">฿<?php echo number_format($p['qty'] * $price, 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="bg-slate-900/50 border border-slate-700 p-4 rounded-xl">
                    <h4 class="text-xs font-bold text-red-400 uppercase mb-2">⚠️ แจ้งเตือนของหมดคลังหรือใกล้หมด (เหลือน้อยกว่า 2)</h4>
                    <?php if (empty($low_stock_items)): ?><p class="text-xs text-emerald-400">อุปกรณ์ทุกรายการยังมีเพียงพอ</p>
                    <?php else: foreach ($low_stock_items as $ls): ?>
                        <div class="flex justify-between bg-slate-950/40 p-2 rounded text-xs mb-1 border <?php echo $ls['qty'] == 0 ? 'border-red-900/50 text-red-400 font-bold' : 'border-slate-800'; ?>">
                            <span>📦 <?php echo htmlspecialchars($ls['name']); ?></span>
                            <span><?php echo $ls['qty'] == 0 ? '🚨 ของหมดคลัง' : 'เหลือแค่ '.$ls['qty']; ?></span>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
                <div class="space-y-3">
                    <h4 class="text-xs font-bold text-cyan-400 uppercase">👤 ค้างยืมรายบุคคล</h4>
                    <?php if (empty($outstanding_borrows)): ?><div class="text-center py-4 text-slate-500 text-xs">ไม่มีผู้ค้างยืม</div>
                    <?php else: foreach ($outstanding_borrows as $user => $items): ?>
                        <div class="bg-slate-900/70 border border-slate-700 p-4 rounded-xl mb-3">
                            <div class="flex justify-between items-center border-b border-slate-700/60 pb-2 mb-2">
                                <div class="text-sm font-bold text-amber-400">ผู้ยืม: <?php echo htmlspecialchars($user); ?></div>
                                <button onclick="confirmClearUserBorrow('<?php echo htmlspecialchars($user, ENT_QUOTES); ?>')" class="text-[10px] bg-red-500/20 text-red-400 hover:bg-red-500/40 px-2.5 py-1 rounded">❌ ล้างค้างยืมทั้งหมดคนนี้</button>
                            </div>
                            <div class="space-y-1 text-xs">
                                <?php foreach($items as $p_id => $data): ?>
                                    <div class="flex justify-between text-slate-300">
                                        <span>• <?php echo htmlspecialchars($data['name']); ?></span>
                                        <span class="font-mono text-cyan-400"><?php echo $data['qty']; ?> ชิ้น</span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div id="historyModal" class="fixed inset-0 z-50 hidden bg-black/70 flex items-center justify-center p-4">
        <div class="bg-slate-800 w-full max-w-3xl h-[85vh] rounded-2xl flex flex-col shadow-2xl overflow-hidden border border-slate-700">
            <div class="p-4 border-b border-slate-700 flex justify-between"><h3 class="font-bold text-slate-100">📜 ประวัติธุรกรรมและสรุปรายเดือน</h3><button onclick="toggleModal('historyModal')" class="text-slate-400 text-xl">&times;</button></div>
            <div class="p-4 overflow-y-auto space-y-5 no-scrollbar flex-grow">
                <div class="bg-slate-900/50 border border-slate-700 p-4 rounded-xl">
                    <h4 class="text-xs font-bold text-cyan-400 uppercase mb-2">📊 ปริมาณการยืม-คืน แยกรายเดือน</h4>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        <?php if(empty($monthly_report)): ?><p class="text-xs text-slate-500">ไม่มีข้อมูลบันทึกประจำเดือน</p>
                        <?php else: foreach($monthly_report as $month => $metrics): ?>
                            <div class="bg-slate-950/40 p-2.5 rounded-lg text-xs flex justify-between">
                                <span class="font-bold text-slate-300 font-mono"><?php echo $month; ?></span>
                                <span class="text-slate-400">ยืม: <b class="text-cyan-400"><?php echo $metrics['borrow_count']; ?></b> | คืน: <b class="text-emerald-400"><?php echo $metrics['return_count']; ?></b></span>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
                <div>
                    <h4 class="text-xs font-bold text-slate-400 uppercase mb-2">ประวัติการทำรายการล่าสุด (100 รายการล่าสุด)</h4>
                    <div class="bg-slate-950/60 border border-slate-700 rounded-xl overflow-x-auto text-[11px]">
                        <table class="w-full text-left whitespace-nowrap">
                            <thead class="bg-slate-900 text-slate-400 border-b border-slate-800">
                                <tr>
                                    <th class="px-3 py-2">วัน-เวลา</th>
                                    <th class="px-3 py-2">ผู้ใช้งาน</th>
                                    <th class="px-3 py-2 text-center">กิจกรรม</th>
                                    <th class="px-3 py-2">รายการอุปกรณ์</th>
                                    <th class="px-3 py-2 text-center">จำนวน</th>
                                    <th class="px-3 py-2">เหตุผล/บันทึก</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800 text-slate-300">
                                <?php if(empty($history)): ?><tr><td colspan="6" class="text-center py-4 text-slate-500">ยังไม่มีข้อมูลประวัติในระบบ</td></tr>
                                <?php else: foreach(array_reverse($history) as $idx => $h): if($idx >= 100) break; ?>
                                <tr>
                                    <td class="px-3 py-2 font-mono text-slate-400"><?php echo htmlspecialchars($h['date'] ?? '-'); ?></td>
                                    <td class="px-3 py-2 text-cyan-400">👤 <?php echo htmlspecialchars($h['user']); ?></td>
                                    <td class="px-3 py-2 text-center"><span class="px-1.5 py-0.5 rounded text-[10px] <?php echo $h['action'] === 'ยืม' ? 'bg-cyan-500/10 text-cyan-400' : 'bg-emerald-500/10 text-emerald-400'; ?>"><?php echo htmlspecialchars($h['action']); ?></span></td>
                                    <td class="px-3 py-2"><?php echo htmlspecialchars($h['name']); ?></td>
                                    <td class="px-3 py-2 text-center font-bold font-mono"><?php echo htmlspecialchars($h['qty'] ?? 1); ?></td>
                                    <td class="px-3 py-2 text-slate-400 max-w-xs truncate"><?php echo htmlspecialchars($h['reason'] ?? '-'); ?></td>
                                </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="profileModal" class="fixed inset-0 z-50 hidden bg-black/70 flex items-center justify-center p-4">
        <div class="bg-slate-800 w-full max-w-md rounded-2xl shadow-2xl border border-slate-700 overflow-hidden">
            <div class="p-4 border-b border-slate-700 flex justify-between"><h3 class="font-bold text-slate-100">⚙️ แก้ไขข้อมูลรหัสผ่าน</h3><button onclick="toggleModal('profileModal')" class="text-slate-400 text-xl">&times;</button></div>
            <form method="POST" class="p-4 space-y-4 text-xs">
                <div><label class="block text-slate-400 mb-1">Username ของคุณ</label><input type="text" disabled value="<?php echo htmlspecialchars($current_user); ?>" class="w-full bg-slate-900/60 border border-slate-700 rounded-lg px-3 py-2 text-slate-400 cursor-not-allowed"></div>
                <div><label class="block text-slate-400 mb-1">รหัสผ่านใหม่ (หากต้องการเปลี่ยน)</label><input type="text" name="profile_password" required placeholder="ใส่รหัสผ่านใหม่ที่ต้องการใช้ที่นี่" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-slate-200 focus:outline-none"></div>
                <div class="pt-2"><button type="submit" name="update_profile" class="w-full bg-purple-600 hover:bg-purple-500 text-white font-semibold py-2.5 rounded-xl transition">อัปเดตรหัสผ่านใหม่</button></div>
            </form>
        </div>
    </div>

    <?php if ($role === 'admin'): ?>
    <div id="productFormModal" class="fixed inset-0 z-50 hidden bg-black/70 flex items-center justify-center p-4">
        <div class="bg-slate-800 w-full max-w-md rounded-2xl shadow-2xl border border-slate-700 overflow-hidden">
            <div class="p-4 border-b border-slate-700 flex justify-between"><h3 id="modalProductTitle" class="font-bold text-slate-100">📦 จัดการพัสดุอุปกรณ์</h3><button onclick="toggleModal('productFormModal')" class="text-slate-400 text-xl">&times;</button></div>
            <form id="productForm" method="POST" enctype="multipart/form-data" class="p-4 space-y-3 text-xs">
                <input type="hidden" name="old_id" id="prod_old_id">
                <div><label class="block text-slate-400 mb-1">รหัสสินค้า / อุปกรณ์ (ID)</label><input type="text" name="id" id="prod_id" required class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-slate-200"></div>
                <div><label class="block text-slate-400 mb-1">ชื่ออุปกรณ์ IT</label><input type="text" name="name" id="prod_name" required class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-slate-200"></div>
                <div class="grid grid-cols-2 gap-2">
                    <div><label class="block text-slate-400 mb-1">จำนวนคงคลังในระบบ</label><input type="number" name="qty" id="prod_qty" required min="0" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-slate-200"></div>
                    <div><label class="block text-slate-400 mb-1">หน่วยนับ</label><input type="text" name="unit" id="prod_unit" required placeholder="ชิ้น, กล่อง, ชุด" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-slate-200"></div>
                </div>
                <div><label class="block text-slate-400 mb-1">ราคาโดยประมาณต่อหน่วย (บาท)</label><input type="number" name="price" id="prod_price" required min="0" step="0.01" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-slate-200"></div>
                <div><label class="block text-slate-400 mb-1">อัปโหลดภาพพัสดุ (ปล่อยว่างได้ถ้าไม่ต้องการเปลี่ยน)</label><input type="file" name="img_file" accept="image/*" class="w-full text-slate-400 text-xs"></div>
                <div class="pt-2"><button type="submit" id="btnProductSubmit" class="w-full font-semibold py-2.5 rounded-xl text-white transition-all"></button></div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script>
        // ฟังก์ชันเปิด-ปิด แถบเมนู 3 ขีด (Sidebar)
        function toggleSidebar() {
            const sb = document.getElementById('sidebarMenu');
            if(sb.classList.contains('-translate-x-full')) {
                sb.classList.remove('-translate-x-full');
            } else {
                sb.classList.add('-translate-x-full');
            }
        }
        function closeSidebar() { document.getElementById('sidebarMenu').classList.add('-translate-x-full'); }
        
        // ฟังก์ชันเปิด-ปิด Modals
        function toggleModal(id) { document.getElementById(id).classList.toggle('hidden'); }
        
        // ฟังก์ชันช่วยยิง POST Request ไปทำรายการเบื้องหลัง
        function sendPostRequest(params) {
            const form = document.createElement('form'); form.method = 'POST'; form.action = '';
            for (const key in params) { const input = document.createElement('input'); input.type = 'hidden'; input.name = key; input.value = params[key]; form.appendChild(input); }
            document.body.appendChild(form); form.submit();
        }

        // ระบบค้นหาสินค้า Real-time หน้าแรก
        function searchProducts() {
            const q = document.getElementById('searchInput').value.toLowerCase().trim();
            const cards = document.querySelectorAll('.product-card');
            let hasItem = false;
            cards.forEach(c => {
                const name = c.getAttribute('data-name').toLowerCase();
                const id = c.getAttribute('data-id').toLowerCase();
                if(name.includes(q) || id.includes(q)) {
                    c.classList.remove('hidden'); hasItem = true;
                } else {
                    c.classList.add('hidden');
                }
            });
            document.getElementById('noSearchItem').classList.toggle('hidden', hasItem);
        }

        // SweetAlert หน้าต่างกรอกข้อมูลการยืมสินค้า
        function borrowItem(id, name, maxQty) {
            if(maxQty <= 0) {
                Swal.fire({ icon: 'error', title: 'สินค้าชิ้นนี้หมดแล้ว!', text: 'ไม่สามารถทำรายการยืมคลังได้ เนื่องจากยอดอุปกรณ์เหลือ 0', confirmButtonColor: '#ef4444' });
                return;
            }
            Swal.fire({
                title: '⬇️ ยืมอุปกรณ์ IT',
                html: `<div class="space-y-3 text-left text-xs">
                        <div class="text-cyan-400 font-semibold font-mono mb-1">รายการ: ${name}</div>
                        <div><label class="block text-slate-400 mb-1">จำนวนคลังที่จะขอยืม (สูงสุดได้: ${maxQty})</label><input id="swal-qty" type="number" class="w-full bg-slate-900 border border-slate-700 text-slate-100 rounded-lg px-3 py-2" min="1" max="${maxQty}" value="1"></div>
                        <div><label class="block text-slate-400 mb-1">ระบุเหตุผลการเบิกยืมพัสดุ</label><input id="swal-reason" type="text" class="w-full bg-slate-900 border border-slate-700 text-slate-100 rounded-lg px-3 py-2" placeholder="เช่น นำไปใช้จัดงานประชุมสัมมนา"></div>
                       </div>`,
                showCancelButton: true, confirmButtonText: 'ยืนยันทำรายการยืม', cancelButtonText: 'ยกเลิก', confirmButtonColor: '#0891b2',
                preConfirm: () => {
                    const qty = parseInt(document.getElementById('swal-qty').value);
                    const reason = document.getElementById('swal-reason').value.trim();
                    if (!qty || qty <= 0 || qty > maxQty) { Swal.showValidationMessage('จำนวนที่ระบุไม่ถูกต้องหรือเกินคลังสินค้า'); return false; }
                    if (!reason) { Swal.showValidationMessage('กรุณาระบุรายละเอียดเหตุผลการยืม'); return false; }
                    return { qty: qty, reason: reason };
                }
            }).then((res) => { if (res.isConfirmed) sendPostRequest({ action_borrow: 1, p_id: id, qty: res.value.qty, reason: res.value.reason }); });
        }

        // SweetAlert หน้าต่างกรอกข้อมูลส่งคืนสินค้า
        function returnItem(id, name) {
            Swal.fire({
                title: '⬆️ คืนสินค้าเข้าคลัง',
                html: `<div class="text-left text-xs">
                        <div class="text-emerald-400 font-semibold mb-2">รายการ: ${name}</div>
                        <label class="block text-slate-400 mb-1">ระบุจำนวนหน่วยพัสดุที่ต้องการนำส่งคืน</label>
                        <input id="swal-return-qty" type="number" class="w-full bg-slate-900 border border-slate-700 text-slate-100 rounded-lg px-3 py-2" min="1" value="1">
                       </div>`,
                showCancelButton: true, confirmButtonText: 'ยืนยันส่งคืนพัสดุ', cancelButtonText: 'ยกเลิก', confirmButtonColor: '#059669',
                preConfirm: () => {
                    const qty = parseInt(document.getElementById('swal-return-qty').value);
                    if (!qty || qty <= 0) { Swal.showValidationMessage('กรุณากรอกจำนวนตัวเลขที่ถูกต้อง'); return false; }
                    return qty;
                }
            }).then((res) => { if (res.isConfirmed) sendPostRequest({ action_return: 1, p_id: id, qty: res.value }); });
        }

        <?php if ($role === 'admin'): ?>
        // จัดการเปิดฟอร์มแอดมินสำหรับเพิ่มของเข้าใหม่
        function openAddModal() {
            document.getElementById('productForm').reset(); document.getElementById('prod_old_id').value = '';
            document.getElementById('modalProductTitle').innerText = '➕ เพิ่มอุปกรณ์ชิ้นใหม่เข้าสู่คลังระบบ';
            document.getElementById('btnProductSubmit').innerText = 'บันทึกของเข้าคลังใหม่ & ซิงค์ชีต';
            document.getElementById('btnProductSubmit').className = 'w-full bg-emerald-600 hover:bg-emerald-500 py-2.5 rounded-xl font-semibold text-white transition';
            document.getElementById('btnProductSubmit').name = 'add_product';
            toggleModal('productFormModal');
        }
        // จัดการเปิดฟอร์มแก้ไข/เติมสินค้าเดิมของแอดมิน
        function openEditModal(p) {
            document.getElementById('prod_old_id').value = p.id; document.getElementById('prod_id').value = p.id;
            document.getElementById('prod_name').value = p.name; document.getElementById('prod_qty').value = p.qty;
            document.getElementById('prod_unit').value = p.unit; document.getElementById('prod_price').value = p.price || 0;
            document.getElementById('modalProductTitle').innerText = '🔧 แก้ไขข้อมูลอุปกรณ์ / อัปเดตสต็อกเพิ่ม';
            document.getElementById('btnProductSubmit').innerText = 'ยืนยันการปรับยอดสต็อก & บันทึกประวัติ';
            document.getElementById('btnProductSubmit').className = 'w-full bg-purple-600 hover:bg-purple-500 py-2.5 rounded-xl font-semibold text-white transition';
            document.getElementById('btnProductSubmit').name = 'edit_product';
            toggleModal('productFormModal');
        }
        // แจ้งเตือนยืนยันก่อนลบสินค้า
        function confirmDelete(id) {
            Swal.fire({ title: '⚠️ แน่ใจใช่ไหมที่จะลบพัสดุ?', text: "การลบรายการอุปกรณ์นี้จะไม่สามารถกู้กลับคืนมาได้อีก!", icon: 'warning', showCancelButton: true, confirmButtonColor: '#d33', cancelButtonColor: '#3085d6', confirmButtonText: 'ลบออกทันที', cancelButtonText: 'ยกเลิก' })
            .then((res) => { if (res.isConfirmed) window.location.href = `?action=delete&id=${id}`; });
        }
        // แจ้งเตือนยืนยันก่อนลบผู้ใช้งาน
        function confirmDeleteUser(username) {
            Swal.fire({ title: '🚨 ต้องการลบผู้ใช้รายนี้?', text: `ชื่อบัญชี: ${username} จะถูกตัดสิทธิ์และลบออกจากระบบ`, icon: 'warning', showCancelButton: true, confirmButtonColor: '#d33', confirmButtonText: 'ยืนยันลบ', cancelButtonText: 'ยกเลิก' })
            .then((res) => { if (res.isConfirmed) window.location.href = `?action=delete_user&username=${encodeURIComponent(username)}`; });
        }
        // แจ้งเตือนยืนยันเคลียร์ค้างยืมของยูสเซอร์
        function confirmClearUserBorrow(username) {
            Swal.fire({ title: 'ล้างข้อมูลค้างยืม?', text: `ระบบจะล้างประวัติที่ค้างยืมทั้งหมดของ ${username} ให้กลับเป็นศูนย์`, icon: 'warning', showCancelButton: true, confirmButtonColor: '#f59e0b', confirmButtonText: 'ยืนยันเคลียร์', cancelButtonText: 'ยกเลิก' })
            .then((res) => { if (res.isConfirmed) window.location.href = `?action=clear_user_borrow&username=${encodeURIComponent(username)}`; });
        }
        // แจ้งเตือนล้างของในคลังทั้งหมดเป็น 0
        function confirmClearAllStock() {
            Swal.fire({ title: '⚠️ รีเซ็ตสินค้าทั้งหมดเป็น 0?', text: "จำนวนของคงเหลือของทุกรายการในระบบจะถูกล้างค่าเซ็ตเป็นศูนย์ทั้งหมด!", icon: 'danger', showCancelButton: true, confirmButtonColor: '#d33', confirmButtonText: 'ล้างคลังทั้งหมด', cancelButtonText: 'ยกเลิก' })
            .then((res) => { if (res.isConfirmed) window.location.href = '?action=clear_all_stock'; });
        }
        <?php endif; ?>

        // สคริปต์ดักสเตตัสเพื่อแสดงหน้าต่างแจ้งเตือนจากหน้าเซิร์ฟเวอร์
        document.addEventListener("DOMContentLoaded", function() {
            const status = new URLSearchParams(window.location.search).get('status');
            if (status) {
                let msg = "ทำรายการเรียบร้อยแล้ว!";
                let icon = "success";
                if (status === 'borrow_success') msg = "ยืมพัสดุสำเร็จ! ข้อมูลถูกอัปเดตเข้าระบบและ Google Sheet แล้ว";
                if (status === 'return_success') msg = "ส่งคืนพัสดุกลับเข้าสต็อกเรียบร้อย และซิงค์รายงานแผ่นชีตเรียบร้อย!";
                if (status === 'product_added') msg = "เพิ่มข้อมูลสินค้าตัวใหม่เข้าคลังสต็อกและชีตแล้ว";
                if (status === 'product_updated') msg = "อัปเดตข้อมูลรายละเอียดสินค้าและปรับสต็อกเรียบร้อย!";
                if (status === 'product_deleted') { msg = "ลบรายการอุปกรณ์ดังกล่าวออกจากฐานข้อมูลคลังสำเร็จ"; icon = "info"; }
                if (status === 'user_added') msg = "สร้างบัญชีผู้ใช้งานคนใหม่เรียบร้อย";
                if (status === 'user_deleted') { msg = "ทำการลบบัญชีผู้ใช้งานที่เลือกแล้ว"; icon = "info"; }
                if (status === 'profile_updated') msg = "เปลี่ยนรหัสผ่านประจำตัวของคุณสำเร็จ!";
                if (status === 'stock_cleared') { msg = "ปรับทุกยอดสินค้าคงคลังให้เป็น 0 เรียบร้อยแล้ว"; icon = "warning"; }
                if (status === 'clear_borrow_success') { msg = "ล้างยอดค้างยืมของพนักงานรายบุคคลเรียบร้อย"; icon = "info"; }
                if (status === 'error_not_borrowed') { msg = "เกิดข้อผิดพลาด: คุณไม่มีประวัติค้างยืมอุปกรณ์รายการนี้ในระบบ"; icon = "error"; }
                if (status === 'error_return_over') { msg = "เกิดข้อผิดพลาด: คุณระบุจำนวนส่งคืน เกินกว่าจำนวนที่ขอยืมไปจริง"; icon = "error"; }
                if (status === 'user_exists') { msg = "ไม่สามารถสร้างได้: ตรวจพบชื่อผู้ใช้ (Username) นี้ซ้ำในระบบ"; icon = "error"; }

                Swal.fire({ icon: icon, title: msg, confirmButtonColor: '#06b6d4' })
                    .then(() => { window.history.replaceState({}, document.title, window.location.pathname); });
            }
        });
    </script>
    <?php endif; ?>
</body>
</html>
