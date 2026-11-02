-- Online Cake & Bakery Shop - schema (matches ERD in Chapter 2.3.4)
-- NOTE: importing this file RESETS the database (drops and recreates all tables).
CREATE DATABASE IF NOT EXISTS cakeb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE cakeb;
SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS notifications, payments, order_items, orders, products, categories, users, delivery_zones;
SET FOREIGN_KEY_CHECKS=1;

CREATE TABLE users (
  user_id INT AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(100) NOT NULL,
  email VARCHAR(120) NOT NULL UNIQUE,
  phone VARCHAR(15) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('customer','admin') NOT NULL DEFAULT 'customer',
  failed_attempts TINYINT NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  deleted_at DATETIME NULL,               -- set when an admin deletes a customer who has order history (personal data is erased, row kept)
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE delivery_zones (
  zone_id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL UNIQUE,
  fee DECIMAL(10,2) NOT NULL CHECK (fee >= 0),
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE categories (
  category_id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE products (
  product_id INT AUTO_INCREMENT PRIMARY KEY,
  category_id INT NOT NULL,
  name VARCHAR(120) NOT NULL,
  description TEXT,
  price DECIMAL(10,2) NOT NULL CHECK (price >= 0),
  stock_qty INT NOT NULL DEFAULT 0 CHECK (stock_qty >= 0),
  low_stock_level INT NOT NULL DEFAULT 5,
  size_label VARCHAR(20) NULL,            -- e.g. '1 kg'  -> "Chocolate Cake (1 kg)" in the cart
  pack_size INT NULL,                     -- e.g. 6       -> "Vanilla Cupcakes (6)", "2 boxes of 6"
  badge ENUM('best_seller','new') NULL,   -- pill shown on the catalogue card
  is_offer TINYINT(1) NOT NULL DEFAULT 0, -- shown under the "Offers" filter
  occasion ENUM('birthday','wedding') NULL,
  image VARCHAR(255) NOT NULL DEFAULT 'white-bread.png',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (category_id) REFERENCES categories(category_id)
) ENGINE=InnoDB;

CREATE TABLE orders (
  order_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  order_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  date_needed DATETIME NOT NULL,
  contact_name VARCHAR(100) NOT NULL,
  contact_phone VARCHAR(15) NOT NULL,
  fulfilment ENUM('pickup','delivery') NOT NULL DEFAULT 'pickup',
  destination VARCHAR(80) NULL,           -- delivery destination chosen at checkout (e.g. 'Thika')
  delivery_address VARCHAR(255) NULL,      -- street / building / landmark typed by the customer
  special_instructions VARCHAR(255) NULL,
  delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
  total_amount DECIMAL(10,2) NOT NULL,
  status ENUM('new','in_progress','ready','delivered','cancelled') NOT NULL DEFAULT 'new',
  deleted_at DATETIME NULL,                -- admin 'delete' of a delivered order: hidden from lists, record kept for accounting
  FOREIGN KEY (user_id) REFERENCES users(user_id)
) ENGINE=InnoDB AUTO_INCREMENT=1001;

CREATE TABLE order_items (
  item_id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  product_id INT NOT NULL,
  quantity INT NOT NULL CHECK (quantity > 0),
  unit_price DECIMAL(10,2) NOT NULL,
  cake_message VARCHAR(120) NULL,
  FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(product_id)
) ENGINE=InnoDB;

CREATE TABLE payments (
  payment_id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL UNIQUE,
  method ENUM('mpesa','cash_on_pickup') NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  mpesa_receipt VARCHAR(30) NULL,
  checkout_request_id VARCHAR(100) NULL,
  status ENUM('pending','paid','failed') NOT NULL DEFAULT 'pending',
  paid_at DATETIME NULL,
  payer_phone VARCHAR(15) NULL,            -- number that received the STK prompt (2547XXXXXXXX)
  paid_amount DECIMAL(10,2) NULL,          -- amount Safaricom says was paid
  merchant_request_id VARCHAR(100) NULL,
  result_code INT NULL,                    -- Daraja ResultCode (0 = success, 1032 = cancelled, 1037 = timeout ...)
  result_desc VARCHAR(255) NULL,
  requested_at DATETIME NULL,              -- when the STK prompt was sent
  FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE notifications (
  notification_id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  phone VARCHAR(15) NOT NULL,
  message VARCHAR(320) NOT NULL,
  delivery_status ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
  sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_orders_status ON orders(status);
CREATE INDEX idx_orders_date ON orders(order_date);
CREATE INDEX idx_products_cat ON products(category_id);

INSERT INTO categories(name) VALUES ('Cakes'),('Cupcakes'),('Bread');

-- Delivery destinations and their fixed fees (Ksh). The server always reads the fee from this table. Editable in Admin > Delivery.
INSERT INTO delivery_zones(name,fee) VALUES
('Within CBD',0),('Between Allsops and Juja',100),('Thika',150),('Murang''a',250),('Nyeri',350),('Nakuru',400),('Mombasa',500);

-- Products exactly as shown in the Milestone 2 mock-ups
INSERT INTO products(category_id,name,description,price,stock_qty,low_stock_level,size_label,pack_size,badge,is_offer,occasion,image) VALUES
(1,'Chocolate Cake','Rich, moist chocolate sponge layered with smooth chocolate ganache and finished with fresh berries and flowers.\nA 1 kg cake that serves about 8 to 10 people, ideal for birthdays and celebrations.\nContains: wheat, eggs, milk. Order at least 24 hours ahead; you can add a message to be written on the cake.',1800,8,3,'1 kg',NULL,'best_seller',0,'birthday','choc-cake.png'),
(2,'Vanilla Cupcakes','A box of 6 soft vanilla cupcakes topped with creamy vanilla frosting and colourful sprinkles.\nBaked fresh on the day. Great for parties, office treats and gifts.\nContains: wheat, eggs, milk.',600,25,5,NULL,6,'new',1,NULL,'vanilla-cupcake.png'),
(3,'White Bread','Soft white loaf baked fresh every morning, with a light golden crust. 400 g.\nPerfect for sandwiches and toast. Best eaten within 3 days.\nContains: wheat.',70,3,5,'400 g',NULL,NULL,0,NULL,'white-bread.png'),
(1,'Red Velvet Cake','Classic red velvet layers with a hint of cocoa, covered in tangy cream cheese frosting. 1 kg, serves about 8 to 10.\nA popular choice for weddings and anniversaries.\nContains: wheat, eggs, milk. Order at least 24 hours ahead.',2200,0,3,'1 kg',NULL,NULL,0,'wedding','red-velvet.png');
-- Create your admin account by opening install.php once (it locks itself afterwards).  Optional demo orders: seed_demo.php (as admin).
