<?php
require_once 'config.php';
session_start();

// Authentication and Authorization
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Felhasználó jogosultság ellenőrzés módosítása
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND (user_type = 'seller' OR user_type = 'admin' OR role = 'admin') AND active = 1");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    $_SESSION['error'] = 'Nincs jogosultsága a kassza használatához!';
    header('Location: index.php');
    exit;
}

// Shop validation
$shop_id = $_GET['shop_id'] ?? null;
if (!$shop_id) {
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM shops WHERE id = ?");
$stmt->execute([$shop_id]);
$shop = $stmt->fetch();

if (!$shop) {
    header('Location: index.php');
    exit;
}

// Fetch available products for this shop
$stmt = $pdo->prepare("
    SELECT p.*, 
           COALESCE(d.quantity, 0) as available_stock
    FROM products p
    JOIN deliveries d ON p.id = d.product_id
    WHERE d.shop_id = ? AND p.active = 1
    GROUP BY p.id, d.shop_id
");
$stmt->execute([$shop_id]);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Save receipt
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_receipt'])) {
    try {
        $pdo->beginTransaction();

        // Generate unique receipt number
        $receipt_number = 'NYU-' . date('Ymd') . '-' . uniqid();
        $payment_type = $_POST['payment_type'];
        $total_amount = 0;

        // Insert receipt header
        $stmt = $pdo->prepare("
            INSERT INTO receipts (
                shop_id, 
                receipt_number, 
                total_amount, 
                payment_type, 
                created_by
            ) VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $shop_id, 
            $receipt_number, 
            0, 
            $payment_type, 
            $_SESSION['user_id']
        ]);
        $receipt_id = $pdo->lastInsertId();

        // Process receipt items
        $items = json_decode($_POST['receipt_items'], true);
        $total_amount = 0;

        foreach ($items as $item) {
            $stmt = $pdo->prepare("
                INSERT INTO receipt_items (
                    receipt_id, 
                    product_id, 
                    quantity, 
                    unit_price, 
                    total_price,
                    vat_rate
                ) VALUES (?, ?, ?, ?, ?, ?)
            ");
            $total_price = $item['quantity'] * $item['price'];
            $total_amount += $total_price;

            $stmt->execute([
                $receipt_id,
                $item['id'],
                $item['quantity'],
                $item['price'],
                $total_price,
                $item['vat_rate'] ?? 27 // Default VAT rate
            ]);
        }

        // Update total amount in receipt
        $stmt = $pdo->prepare("
            UPDATE receipts 
            SET total_amount = ? 
            WHERE id = ?
        ");
        $stmt->execute([$total_amount, $receipt_id]);

        // Create transaction
        $stmt = $pdo->prepare("
            INSERT INTO transactions (
                shop_id, 
                type, 
                payment_type, 
                amount, 
                created_by
            ) VALUES (?, 'income', ?, ?, ?)
        ");
        $stmt->execute([
            $shop_id, 
            $payment_type, 
            $total_amount, 
            $_SESSION['user_id']
        ]);

        $pdo->commit();

        // Redirect with success message
        $_SESSION['success'] = 'Nyugta sikeresen mentve!';
        header('Location: pos.php?shop_id=' . $shop_id);
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = 'Hiba történt: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kassza - <?php echo htmlspecialchars($shop['name']); ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #34495e;
            --accent-color: #e67e22;
            --success-color: #27ae60;
            --danger-color: #e74c3c;
            --warning-color: #f1c40f;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
        }

        body {
            background: linear-gradient(135deg, #f6f8fa 0%, #e9ecef 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        }

        .pos-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        .product-grid {
            max-height: 70vh;
            overflow-y: auto;
        }

        .product-card {
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }

        .product-card:hover {
            border-color: var(--accent-color);
            transform: scale(1.02);
        }

        .cart-item {
            transition: all 0.3s ease;
        }

        .cart-item:hover {
            background-color: rgba(230, 126, 34, 0.05);
        }

        .barcode-input {
            position: absolute;
            opacity: 0;
            z-index: -1;
        }

        .payment-methods .btn {
            padding: 1rem;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .search-input {
            border-radius: 20px;
            padding-left: 30px;
        }

        .search-icon {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--secondary-color);
        }
    </style>
</head>
<body>
<header class="main-header">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1 class="h3 mb-0">Kassza - <?php echo htmlspecialchars($shop['name']); ?></h1>
            </div>
            <div class="d-flex gap-3 align-items-center">
                <a href="index.php" class="btn btn-light">
                    <i class="fas fa-home me-2"></i>Főoldal
                </a>
                <a href="logout.php" class="btn btn-danger">
                    <i class="fas fa-sign-out-alt me-2"></i>Kijelentkezés
                </a>
            </div>
        </div>
    </div>
</header>
    <div class="container-fluid py-4">
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php 
                echo htmlspecialchars($_SESSION['success']);
                unset($_SESSION['success']);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php 
                echo htmlspecialchars($_SESSION['error']);
                unset($_SESSION['error']);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-md-8">
                <div class="pos-container h-100 p-4">
                    <div class="row mb-4">
                        <div class="col-md-6 position-relative">
                            <i class="fas fa-search search-icon"></i>
                            <input type="text" id="product-search" 
                                   class="form-control search-input" 
                                   placeholder="Termék keresése...">
                            <input type="text" id="barcode-input" 
                                   class="barcode-input" 
                                   autofocus>
                        </div>
                        <div class="col-md-6 text-end">
                            <h4 class="m-0">
                                <i class="fas fa-store me-2"></i>
                                <?php echo htmlspecialchars($shop['name']); ?>
                            </h4>
                        </div>
                    </div>

                    <div class="row product-grid">
                        <?php foreach ($products as $product): ?>
                            <div class="col-md-3 mb-3 product-item" 
                                 data-id="<?php echo $product['id']; ?>"
                                 data-name="<?php echo htmlspecialchars($product['name']); ?>"
                                 data-price="<?php echo $product['price']; ?>"
                                 data-vat="27"
                                 data-stock="<?php echo $product['available_stock']; ?>">
                                <div class="card product-card h-100">
                                    <div class="card-body text-center">
                                        <h6 class="card-title"><?php echo htmlspecialchars($product['name']); ?></h6>
                                        <p class="text-muted">
                                            <?php echo number_format($product['price'], 0, ',', ' '); ?> Ft
                                        </p>
                                        <span class="badge bg-success">
                                            <?php echo $product['available_stock']; ?> db
                                        </span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="pos-container h-100 p-4">
                    <h5 class="mb-4">
                        <i class="fas fa-shopping-cart me-2"></i>
                        Kosár
                    </h5>

                    <div id="cart-items" class="mb-4">
                        <!-- Cart items will be dynamically added here -->
                    </div>

                    <div class="mb-4">
                        <div class="d-flex justify-content-between mb-2">
                            <strong>Összesen:</strong>
                            <span id="cart-total">0 Ft</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <strong>ÁFA (27%):</strong>
                            <span id="cart-vat">0 Ft</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <strong>Végösszeg:</strong>
                            <span id="cart-final-total">0 Ft</span>
                        </div>
                    </div>

                    <div class="payment-methods">
                        <h6 class="mb-3">Fizetési mód</h6>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <button class="btn btn-outline-primary w-100 payment-btn" data-type="kassza1">
                                    <i class="fas fa-cash-register"></i> Kassza 1
                                </button>
                            </div>
                            <div class="col-md-4">
                                <button class="btn btn-outline-primary w-100 payment-btn" data-type="kassza2">
                                    <i class="fas fa-cash-register"></i> Kassza 2
                                </button>
                            </div>
                            <div class="col-md-4">
                                <button class="btn btn-outline-primary w-100 payment-btn" data-type="kartyasFizetes">
                                    <i class="fas fa-credit-card"></i> Kártya
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <form id="receipt-form" method="POST">
        <input type="hidden" name="receipt_items" id="receipt-items-input">
        <input type="hidden" name="payment_type" id="payment-type-input">
        <input type="hidden" name="save_receipt" value="1">
    </form>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
    <script>
        // POS System JavaScript
        document.addEventListener('DOMContentLoaded', function() {
            const productSearch = document.getElementById('product-search');
            const barcodeInput = document.getElementById('barcode-input');
            const productItems = document.querySelectorAll('.product-item');
            const cartItemsContainer = document.getElementById('cart-items');
            const cartTotalElement = document.getElementById('cart-total');
            const cartVatElement = document.getElementById('cart-vat');
            const cartFinalTotalElement = document.getElementById('cart-final-total');
            const paymentButtons = document.querySelectorAll('.payment-btn');
            const receiptForm = document.getElementById('receipt-form');
            const receiptItemsInput = document.getElementById('receipt-items-input');
            const paymentTypeInput = document.getElementById('payment-type-input');

            let cart = [];
            let paymentType = null;

            // Product Search Functionality
            productSearch.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                productItems.forEach(item => {
                    const name = item.dataset.name.toLowerCase();
                    item.style.display = name.includes(searchTerm) ? 'block' : 'none';
                });
            });

            // Product Click Handler
            productItems.forEach(item => {
                item.addEventListener('click', function() {
                    addToCart(this.dataset);
                });
            });

            // Barcode Input Handling
            barcodeInput.addEventListener('change', function() {
                const barcode = this.value.trim();
                const product = Array.from(productItems).find(p => p.dataset.barcode === barcode);
                
                if (product) {
                    addToCart(product.dataset);
                } else {
                    alert('Termék nem található!');
                }

                // Reset barcode input
                this.value = '';
                this.focus();
            });

            // Add to Cart Function
            function addToCart(productData) {
    // Check stock availability
    const stock = parseInt(productData.stock);
    if (stock <= 0) {
        alert('Nincs elérhető készlet!');
        return;
    }

    // Check if product already in cart
    const existingCartItem = cart.find(item => item.id === productData.id);
    
    if (existingCartItem) {
        // Increment quantity if already in cart
        if (existingCartItem.quantity < stock) {
            existingCartItem.quantity++;
        } else {
            alert('Nem növelhető tovább a mennyiség a készlet miatt!');
            return;
        }
    } else {
        // Add new item to cart
        cart.push({
            id: productData.id,
            name: productData.name,
            price: parseFloat(productData.price),
            quantity: 1,
            vat_rate: 27
        });
    }

    updateCart();
}

// Update Cart Display and Calculations
function updateCart() {
    // Clear existing cart items
    cartItemsContainer.innerHTML = '';

    let total = 0;
    let vat = 0;

    // Render cart items
    cart.forEach((item, index) => {
        const cartItemElement = document.createElement('div');
        cartItemElement.classList.add('d-flex', 'justify-content-between', 'align-items-center', 'cart-item', 'mb-2', 'p-2');
        
        const itemTotal = item.price * item.quantity;
        const itemVat = itemTotal * 0.27;

        cartItemElement.innerHTML = `
            <div>
                <strong>${item.name}</strong>
                <small class="d-block">${item.price.toLocaleString('hu-HU')} Ft x ${item.quantity}</small>
            </div>
            <div class="d-flex align-items-center">
                <button class="btn btn-sm btn-outline-danger me-2 qty-decrease" data-index="${index}">-</button>
                <span>${item.quantity}</span>
                <button class="btn btn-sm btn-outline-success ms-2 qty-increase" data-index="${index}">+</button>
                <button class="btn btn-sm btn-danger ms-2 remove-item" data-index="${index}">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        `;

        cartItemsContainer.appendChild(cartItemElement);

        total += itemTotal;
        vat += itemVat;
    });

    // Update totals
    cartTotalElement.textContent = total.toLocaleString('hu-HU') + ' Ft';
    cartVatElement.textContent = vat.toLocaleString('hu-HU') + ' Ft';
    cartFinalTotalElement.textContent = (total + vat).toLocaleString('hu-HU') + ' Ft';
}

// Quantity Increase/Decrease
cartItemsContainer.addEventListener('click', function(e) {
    const index = e.target.dataset.index;
    
    if (e.target.classList.contains('qty-increase')) {
        const product = productItems.find(p => p.dataset.id === cart[index].id);
        const stock = parseInt(product.dataset.stock);
        
        if (cart[index].quantity < stock) {
            cart[index].quantity++;
            updateCart();
        } else {
            alert('Nem növelhető tovább a mennyiség a készlet miatt!');
        }
    }

    if (e.target.classList.contains('qty-decrease')) {
        if (cart[index].quantity > 1) {
            cart[index].quantity--;
            updateCart();
        } else {
            // Remove item if quantity is 1
            cart.splice(index, 1);
            updateCart();
        }
    }

    if (e.target.classList.contains('remove-item') || 
        e.target.closest('.remove-item')) {
        cart.splice(index, 1);
        updateCart();
    }
});

// Payment Method Selection
paymentButtons.forEach(button => {
    button.addEventListener('click', function() {
        // Remove active state from all buttons
        paymentButtons.forEach(btn => btn.classList.remove('btn-primary'));
        paymentButtons.forEach(btn => btn.classList.add('btn-outline-primary'));

        // Set active state for clicked button
        this.classList.remove('btn-outline-primary');
        this.classList.add('btn-primary');

        paymentType = this.dataset.type;
    });
});

// Payment and Receipt Submission
window.submitReceipt = function() {
    if (cart.length === 0) {
        alert('A kosár üres!');
        return;
    }

    if (!paymentType) {
        alert('Válasszon fizetési módot!');
        return;
    }

    // Set hidden input values for form submission
    receiptItemsInput.value = JSON.stringify(cart);
    paymentTypeInput.value = paymentType;

    // Submit the receipt form
    receiptForm.submit();
}
        </script>
</body>
</html>