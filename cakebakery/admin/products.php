<?php
require_once __DIR__.'/../includes/auth.php'; require_admin();
$cats = array_column(db()->query('SELECT * FROM categories ORDER BY category_id')->fetchAll(), 'name', 'category_id');
$errors = [];

if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';
    if ($act === 'delete') {
        db()->prepare('UPDATE products SET is_active=0 WHERE product_id=?')->execute([(int)$_POST['product_id']]);
        flash('Product deleted (hidden from the shop; past orders are kept).'); redirect('admin/products.php');
    }
    if ($act === 'save') {
        $id = (int)($_POST['product_id'] ?? 0);
        $name = trim($_POST['name'] ?? ''); $cat = (int)($_POST['category_id'] ?? 0);
        $price = $_POST['price'] ?? ''; $stock = $_POST['stock'] ?? ''; $desc = mb_substr(trim($_POST['description'] ?? ''), 0, 2000);
        if ($name === '') $errors[] = 'Enter a product name.';
        if (!isset($cats[$cat])) $errors[] = 'Choose a category.';
        if (!is_numeric($price) || $price < 0) $errors[] = 'Price must be 0 or more.';
        if (!ctype_digit((string)$stock)) $errors[] = 'Stock must be a whole number (0 or more).';
        $image = null;
        if (!empty($_FILES['image']['name'])) {
            $f = $_FILES['image'];
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            $ok = $f['error']===UPLOAD_ERR_OK && in_array($ext, ['jpg','jpeg','png','webp']) && $f['size'] <= 2*1024*1024 && @getimagesize($f['tmp_name']);
            if (!$ok) $errors[] = 'Image must be a JPG, PNG or WEBP under 2 MB.';
            else { $image = 'uploads/'.bin2hex(random_bytes(8)).'.'.$ext; move_uploaded_file($f['tmp_name'], __DIR__.'/../assets/img/'.$image); }
        }
        if (!$errors) {
            if ($id) {
                db()->prepare('UPDATE products SET name=?,category_id=?,price=?,stock_qty=?,description=?,is_active=1'.($image?',image=?':'').' WHERE product_id=?')
                    ->execute(array_merge([$name,$cat,$price,(int)$stock,$desc], $image?[$image]:[], [$id]));
                flash('Product updated.');
            } else {
                db()->prepare('INSERT INTO products(name,category_id,price,stock_qty,description,image) VALUES(?,?,?,?,?,?)')
                    ->execute([$name,$cat,$price,(int)$stock,$desc,$image ?: 'white-bread.png']);
                flash('Product added.');
            }
            redirect('admin/products.php');
        }
    }
}
$edit = null;
if (isset($_GET['edit'])) { $st = db()->prepare('SELECT * FROM products WHERE product_id=? AND is_active=1'); $st->execute([(int)$_GET['edit']]); $edit = $st->fetch() ?: null; }
$v = fn($k, $d='') => e($_POST[$k] ?? ($edit[$k === 'stock' ? 'stock_qty' : $k] ?? $d));
$products = db()->query('SELECT p.*, c.name cat FROM products p JOIN categories c USING(category_id) WHERE p.is_active=1 ORDER BY p.product_id')->fetchAll();
$layout='admin'; $active='products'; $section='Product Management'; $title='Products'; include __DIR__.'/../includes/header.php'; ?>
<div class="head-row"><h1>Products</h1><a class="btn" href="<?= url('admin/products.php#form') ?>">+ Add Product</a></div>
<div class="tbl-card scroll"><table class="tbl"><thead><tr><th>ID</th><th>Name</th><th>Category</th><th>Price (Ksh)</th><th>Stock</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($products as $p): ?>
<tr><td><?= $p['product_id'] ?></td><td><b><?= e(product_title($p)) ?></b></td><td><?= e($p['cat']) ?></td><td><?= num($p['price']) ?></td>
<td><?= $p['stock_qty'] ?><?= $p['stock_qty'] <= $p['low_stock_level'] ? ' <span class="low">Low</span>' : '' ?></td>
<td style="white-space:nowrap"><a class="pill-btn pill-edit" href="?edit=<?= $p['product_id'] ?>#form">Edit</a>
  <form method="post" style="display:inline" onsubmit="return confirm('Delete this product?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="product_id" value="<?= $p['product_id'] ?>"><button class="pill-btn pill-del">Delete</button></form></td></tr>
<?php endforeach; ?></tbody></table></div>

<form id="form" class="card formcard" method="post" enctype="multipart/form-data">
  <h3>Add / Edit Product</h3>
  <?php foreach ($errors as $er): ?><p class="err"><?= e($er) ?></p><?php endforeach; ?>
  <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="product_id" value="<?= (int)($edit['product_id'] ?? 0) ?>">
  <div class="two"><div><label class="f">Name</label><input class="field" name="name" required value="<?= $v('name') ?>"></div>
    <div><label class="f">Category</label><select class="field" name="category_id"><?php foreach ($cats as $id=>$n): ?><option value="<?= $id ?>" <?= (int)($_POST['category_id'] ?? $edit['category_id'] ?? 0)===$id?'selected':'' ?>><?= e($n) ?> ▾</option><?php endforeach; ?></select></div></div>
  <div class="two"><div><label class="f">Price (Ksh)</label><input class="field" type="number" min="0" step="1" name="price" required value="<?= $v('price') ?>"></div>
    <div><label class="f">Stock</label><input class="field" type="number" min="0" step="1" name="stock" required value="<?= $v('stock') ?>"></div></div>
  <div class="row"><label class="f">Description (customers read this on the product page)</label>
    <textarea class="field" name="description" rows="5" maxlength="2000" placeholder="What is it? Flavour, ingredients, size, how many it serves, allergens…"><?= $v('description') ?></textarea></div>
  <div class="row"><label class="f">Image</label><label class="upload">⇧ <span id="fname">Upload image</span><input type="file" name="image" accept="image/png,image/jpeg,image/webp"></label></div>
  <button class="btn" style="margin-top:8px;padding:16px 40px;text-transform:uppercase">Save Product</button>
  <?php if ($edit): ?> <a class="btn-ghost" href="products.php" style="margin-left:10px">Cancel edit</a><?php endif; ?>
</form>
<?php include __DIR__.'/../includes/footer.php';
