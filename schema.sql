-- ============================================================================
-- Stock Market Simulator - Database Schema
-- Course: Advanced Programming (C# .NET) - Semester 6th BSCS
-- Engine: MySQL 8 / MariaDB (XAMPP)
-- ============================================================================

DROP DATABASE IF EXISTS stock_simulator;
CREATE DATABASE stock_simulator CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE stock_simulator;

-- ----------------------------------------------------------------------------
-- 1. users : registered investors + admins. Guests are NOT stored.
-- ----------------------------------------------------------------------------
CREATE TABLE users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    full_name     VARCHAR(120)        NOT NULL,
    email         VARCHAR(160)        NOT NULL UNIQUE,
    password_hash VARCHAR(255)        NOT NULL,
    role          ENUM('admin','user') NOT NULL DEFAULT 'user',
    cash_balance  DECIMAL(18,2)       NOT NULL DEFAULT 100000.00,
    created_at    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_users_role (role)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- 2. sectors : lookup table -> proves JOIN + GROUP BY usage on analytics
-- ----------------------------------------------------------------------------
CREATE TABLE sectors (
    id       INT AUTO_INCREMENT PRIMARY KEY,
    name     VARCHAR(80) NOT NULL UNIQUE,
    color_hex CHAR(7)    NOT NULL DEFAULT '#3498db'
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- 3. stocks : tradable instruments
-- ----------------------------------------------------------------------------
CREATE TABLE stocks (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    symbol        VARCHAR(16)   NOT NULL UNIQUE,
    company_name  VARCHAR(160)  NOT NULL,
    sector_id     INT           NOT NULL,
    current_price DECIMAL(14,4) NOT NULL,
    prev_close    DECIMAL(14,4) NOT NULL,
    day_high      DECIMAL(14,4) NOT NULL,
    day_low       DECIMAL(14,4) NOT NULL,
    volatility    DECIMAL(6,4)  NOT NULL DEFAULT 0.0200, -- % step size for sim
    total_shares  BIGINT        NOT NULL DEFAULT 1000000,
    is_active     TINYINT(1)    NOT NULL DEFAULT 1,
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_stocks_sector FOREIGN KEY (sector_id) REFERENCES sectors(id),
    INDEX idx_stocks_active (is_active),
    INDEX idx_stocks_sector (sector_id)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- 4. price_history : every tick of every stock (drives the LINE chart)
-- ----------------------------------------------------------------------------
CREATE TABLE price_history (
    id          BIGINT AUTO_INCREMENT PRIMARY KEY,
    stock_id    INT           NOT NULL,
    price       DECIMAL(14,4) NOT NULL,
    recorded_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ph_stock FOREIGN KEY (stock_id) REFERENCES stocks(id) ON DELETE CASCADE,
    INDEX idx_ph_stock_time (stock_id, recorded_at)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- 5. holdings : current per-user portfolio (one row per user/stock)
-- ----------------------------------------------------------------------------
CREATE TABLE holdings (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    stock_id        INT NOT NULL,
    quantity        INT NOT NULL,
    avg_buy_price   DECIMAL(14,4) NOT NULL,
    UNIQUE KEY uk_user_stock (user_id, stock_id),
    CONSTRAINT fk_h_user  FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
    CONSTRAINT fk_h_stock FOREIGN KEY (stock_id) REFERENCES stocks(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- 6. transactions : immutable BUY/SELL log (drives trade-volume bar chart)
-- ----------------------------------------------------------------------------
CREATE TABLE transactions (
    id          BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    stock_id    INT NOT NULL,
    tx_type     ENUM('BUY','SELL') NOT NULL,
    quantity    INT NOT NULL,
    unit_price  DECIMAL(14,4) NOT NULL,
    total       DECIMAL(18,4) NOT NULL,
    fee         DECIMAL(14,4) NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_t_user  FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
    CONSTRAINT fk_t_stock FOREIGN KEY (stock_id) REFERENCES stocks(id) ON DELETE CASCADE,
    INDEX idx_t_user_time (user_id, created_at),
    INDEX idx_t_type      (tx_type)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- 7. watchlist : favourites
-- ----------------------------------------------------------------------------
CREATE TABLE watchlist (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    stock_id   INT NOT NULL,
    added_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_w_user_stock (user_id, stock_id),
    CONSTRAINT fk_w_user  FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
    CONSTRAINT fk_w_stock FOREIGN KEY (stock_id) REFERENCES stocks(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- 8. price_alerts : trigger when a stock crosses a threshold
-- ----------------------------------------------------------------------------
CREATE TABLE price_alerts (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    stock_id        INT NOT NULL,
    threshold_price DECIMAL(14,4) NOT NULL,
    direction       ENUM('ABOVE','BELOW') NOT NULL,
    is_triggered    TINYINT(1) NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    triggered_at    DATETIME NULL,
    CONSTRAINT fk_a_user  FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
    CONSTRAINT fk_a_stock FOREIGN KEY (stock_id) REFERENCES stocks(id) ON DELETE CASCADE,
    INDEX idx_a_user_active (user_id, is_triggered)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- 9. news : market news; impact_pct nudges the simulated price
-- ----------------------------------------------------------------------------
CREATE TABLE news (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    stock_id     INT NULL,
    headline     VARCHAR(220) NOT NULL,
    body         TEXT NOT NULL,
    impact_pct   DECIMAL(6,3) NOT NULL DEFAULT 0,
    published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_n_stock FOREIGN KEY (stock_id) REFERENCES stocks(id) ON DELETE SET NULL,
    INDEX idx_n_published (published_at)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- 10. sessions : bearer-token auth between C# client and PHP API
-- ----------------------------------------------------------------------------
CREATE TABLE sessions (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    token      CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_s_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_s_expires (expires_at)
) ENGINE=InnoDB;

-- ============================================================================
-- SEED DATA
-- ============================================================================

-- Sectors
INSERT INTO sectors (name, color_hex) VALUES
('Technology',    '#3498db'),
('Energy',        '#e67e22'),
('Banking',       '#16a085'),
('Healthcare',    '#e74c3c'),
('Consumer',      '#9b59b6'),
('Automotive',    '#f1c40f'),
('Telecom',       '#1abc9c');

-- NOTE: admin + demo users are created by the one-time bootstrap endpoint:
--   http://localhost/stockapi/setup.php
-- That script uses PHP's password_hash() so the bcrypt hash matches password_verify().
-- Credentials seeded there:
--   admin@stocksim.local / admin123     (role = admin)
--   demo@stocksim.local  / user1234     (role = user)

-- 15 stocks across 7 sectors
INSERT INTO stocks (symbol, company_name, sector_id, current_price, prev_close, day_high, day_low, volatility) VALUES
('AAPL', 'Apple Inc.',                        1, 187.50, 185.20, 188.10, 184.90, 0.0150),
('MSFT', 'Microsoft Corp.',                   1, 412.30, 410.00, 414.20, 408.50, 0.0140),
('GOOGL','Alphabet Inc.',                     1, 156.80, 155.10, 158.00, 154.20, 0.0160),
('NVDA', 'NVIDIA Corp.',                      1, 882.10, 870.00, 895.00, 866.50, 0.0320),
('TSLA', 'Tesla Inc.',                        6, 184.60, 180.00, 187.00, 178.30, 0.0380),
('XOM',  'Exxon Mobil Corp.',                 2, 112.40, 111.20, 113.00, 110.80, 0.0180),
('CVX',  'Chevron Corp.',                     2, 158.20, 157.00, 159.10, 156.40, 0.0170),
('JPM',  'JPMorgan Chase & Co.',              3, 198.10, 196.50, 199.30, 195.80, 0.0150),
('HBL',  'Habib Bank Limited',                3, 122.80, 121.50, 123.50, 120.90, 0.0210),
('PFE',  'Pfizer Inc.',                       4, 28.40,  28.10,  28.60,  27.90,  0.0200),
('JNJ',  'Johnson & Johnson',                 4, 152.30, 151.40, 153.00, 150.80, 0.0130),
('KO',   'Coca-Cola Co.',                     5, 60.80,  60.40,  61.00,  60.10,  0.0110),
('PSO',  'Pakistan State Oil',                2, 178.50, 177.00, 179.20, 176.30, 0.0250),
('OGDC', 'Oil & Gas Development Co.',         2, 142.20, 141.00, 143.00, 140.60, 0.0230),
('TELN', 'Telenor Group',                     7, 31.10,  30.80,  31.30,  30.60,  0.0190);

-- Seed initial price history (a day's worth, hourly) so charts have data on first run
INSERT INTO price_history (stock_id, price, recorded_at)
SELECT s.id, s.prev_close,                    NOW() - INTERVAL 8 HOUR FROM stocks s;
INSERT INTO price_history (stock_id, price, recorded_at)
SELECT s.id, s.prev_close * 1.005,            NOW() - INTERVAL 7 HOUR FROM stocks s;
INSERT INTO price_history (stock_id, price, recorded_at)
SELECT s.id, s.prev_close * 0.998,            NOW() - INTERVAL 6 HOUR FROM stocks s;
INSERT INTO price_history (stock_id, price, recorded_at)
SELECT s.id, s.prev_close * 1.012,            NOW() - INTERVAL 5 HOUR FROM stocks s;
INSERT INTO price_history (stock_id, price, recorded_at)
SELECT s.id, s.prev_close * 1.008,            NOW() - INTERVAL 4 HOUR FROM stocks s;
INSERT INTO price_history (stock_id, price, recorded_at)
SELECT s.id, s.prev_close * 0.995,            NOW() - INTERVAL 3 HOUR FROM stocks s;
INSERT INTO price_history (stock_id, price, recorded_at)
SELECT s.id, s.prev_close * 1.003,            NOW() - INTERVAL 2 HOUR FROM stocks s;
INSERT INTO price_history (stock_id, price, recorded_at)
SELECT s.id, s.current_price,                 NOW() - INTERVAL 1 HOUR FROM stocks s;
INSERT INTO price_history (stock_id, price, recorded_at)
SELECT s.id, s.current_price,                 NOW()                   FROM stocks s;

-- Sample news
INSERT INTO news (stock_id, headline, body, impact_pct) VALUES
(4, 'NVIDIA unveils next-gen AI accelerator',  'New chip delivers 2x perf/watt on transformer workloads.',  2.500),
(5, 'Tesla cuts Model Y price in EU',          'Aggressive pricing aims to defend EV market share.',       -1.800),
(1, 'Apple beats quarterly earnings estimates','iPhone sales up 7% YoY, services revenue at record.',       1.600),
(NULL, 'Fed signals possible rate cut',        'Chair hints at easing if inflation continues to slow.',     0.700),
(13,'PSO posts record refining margins',       'PKR fuel price hike lifts downstream margins.',             1.200);

-- ============================================================================
-- USEFUL VIEWS (used by analytics endpoints)
-- ============================================================================

-- Per-user portfolio valuation (JOIN + per-row arithmetic)
CREATE OR REPLACE VIEW v_portfolio_value AS
SELECT
    h.user_id,
    h.stock_id,
    s.symbol,
    s.company_name,
    h.quantity,
    h.avg_buy_price,
    s.current_price,
    (h.quantity * s.current_price)                                 AS market_value,
    (h.quantity * (s.current_price - h.avg_buy_price))             AS unrealized_pl,
    CASE WHEN h.avg_buy_price = 0 THEN 0
         ELSE ((s.current_price - h.avg_buy_price) / h.avg_buy_price) * 100
    END                                                            AS pl_pct
FROM holdings h
JOIN stocks s ON s.id = h.stock_id;

-- Leaderboard (SUM + JOIN + GROUP BY)
CREATE OR REPLACE VIEW v_leaderboard AS
SELECT
    u.id          AS user_id,
    u.full_name,
    u.cash_balance,
    COALESCE(SUM(h.quantity * s.current_price), 0) AS holdings_value,
    u.cash_balance + COALESCE(SUM(h.quantity * s.current_price), 0) AS net_worth
FROM users u
LEFT JOIN holdings h ON h.user_id = u.id
LEFT JOIN stocks   s ON s.id      = h.stock_id
WHERE u.role = 'user'
GROUP BY u.id, u.full_name, u.cash_balance;
