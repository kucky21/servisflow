-- ServisHub – výchozí databázové schéma
-- Peněžní částky: NUMERIC(12,2), částky jsou uloženy BEZ DPH (pokud není uvedeno jinak).
-- Časové údaje: TIMESTAMPTZ.

CREATE EXTENSION IF NOT EXISTS pg_trgm;

-- Společná funkce pro automatickou aktualizaci updated_at
CREATE OR REPLACE FUNCTION set_updated_at() RETURNS trigger AS $$
BEGIN
    NEW.updated_at = now();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- ---------------------------------------------------------------------------
-- Uživatelé
-- ---------------------------------------------------------------------------
CREATE TABLE users (
    id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    email                 VARCHAR(180) NOT NULL,
    password_hash         VARCHAR(255) NOT NULL,
    first_name            VARCHAR(100) NOT NULL,
    last_name             VARCHAR(100) NOT NULL,
    phone                 VARCHAR(32) NULL,
    role                  VARCHAR(20) NOT NULL,
    is_active             BOOLEAN NOT NULL DEFAULT TRUE,
    failed_login_attempts SMALLINT NOT NULL DEFAULT 0,
    locked_until          TIMESTAMPTZ NULL,
    last_login_at         TIMESTAMPTZ NULL,
    created_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT users_role_check CHECK (role IN ('admin', 'manager', 'technician')),
    CONSTRAINT users_email_format_check CHECK (position('@' IN email) > 1)
);
CREATE UNIQUE INDEX users_email_unique ON users (lower(email));
CREATE INDEX users_role_idx ON users (role) WHERE is_active;
CREATE TRIGGER users_updated_at BEFORE UPDATE ON users FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- ---------------------------------------------------------------------------
-- Nastavení aplikace (klíč/hodnota)
-- ---------------------------------------------------------------------------
CREATE TABLE settings (
    key         VARCHAR(64) PRIMARY KEY,
    value       TEXT NOT NULL,
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE TRIGGER settings_updated_at BEFORE UPDATE ON settings FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- ---------------------------------------------------------------------------
-- Zákazníci
-- ---------------------------------------------------------------------------
CREATE TABLE customers (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    type            VARCHAR(10) NOT NULL DEFAULT 'person',
    name            VARCHAR(200) NOT NULL,
    contact_person  VARCHAR(150) NULL,
    email           VARCHAR(180) NULL,
    phone           VARCHAR(32) NULL,
    street          VARCHAR(200) NULL,
    city            VARCHAR(100) NULL,
    zip             VARCHAR(10) NULL,
    ico             VARCHAR(8) NULL,
    dic             VARCHAR(14) NULL,
    note            TEXT NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT customers_type_check CHECK (type IN ('person', 'company')),
    CONSTRAINT customers_ico_check CHECK (ico IS NULL OR ico ~ '^[0-9]{8}$'),
    CONSTRAINT customers_contact_check CHECK (email IS NOT NULL OR phone IS NOT NULL)
);
CREATE UNIQUE INDEX customers_ico_unique ON customers (ico) WHERE ico IS NOT NULL;
CREATE INDEX customers_name_trgm_idx ON customers USING gin (name gin_trgm_ops);
CREATE INDEX customers_email_idx ON customers (lower(email));
CREATE INDEX customers_created_at_idx ON customers (created_at);
CREATE TRIGGER customers_updated_at BEFORE UPDATE ON customers FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- ---------------------------------------------------------------------------
-- Zařízení zákazníků
-- ---------------------------------------------------------------------------
CREATE TABLE devices (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    customer_id     BIGINT NOT NULL REFERENCES customers (id) ON DELETE CASCADE,
    type            VARCHAR(20) NOT NULL,
    manufacturer    VARCHAR(100) NOT NULL,
    model           VARCHAR(150) NOT NULL,
    serial_number   VARCHAR(100) NULL,
    description     TEXT NULL,
    registered_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT devices_type_check CHECK (type IN ('laptop', 'desktop', 'phone', 'tablet', 'monitor', 'printer', 'other')),
    -- umožňuje složený cizí klíč ze zakázek (zařízení musí patřit zákazníkovi zakázky)
    CONSTRAINT devices_id_customer_unique UNIQUE (id, customer_id)
);
CREATE INDEX devices_customer_idx ON devices (customer_id);
CREATE UNIQUE INDEX devices_serial_unique ON devices (lower(manufacturer), lower(serial_number)) WHERE serial_number IS NOT NULL;
CREATE INDEX devices_serial_trgm_idx ON devices USING gin (serial_number gin_trgm_ops);
CREATE TRIGGER devices_updated_at BEFORE UPDATE ON devices FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- ---------------------------------------------------------------------------
-- Číselné řady zakázek (atomické generování SRV-YYYY-NNNN)
-- ---------------------------------------------------------------------------
CREATE TABLE order_number_sequences (
    year        SMALLINT PRIMARY KEY,
    last_value  INTEGER NOT NULL CHECK (last_value >= 0)
);

-- ---------------------------------------------------------------------------
-- Servisní zakázky
-- ---------------------------------------------------------------------------
CREATE TABLE service_orders (
    id                      BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    order_number            VARCHAR(20) NOT NULL,
    customer_id             BIGINT NOT NULL REFERENCES customers (id) ON DELETE RESTRICT,
    device_id               BIGINT NOT NULL,
    assigned_technician_id  BIGINT NULL REFERENCES users (id) ON DELETE SET NULL,
    created_by_id           BIGINT NULL REFERENCES users (id) ON DELETE SET NULL,
    status                  VARCHAR(24) NOT NULL DEFAULT 'received',
    priority                VARCHAR(10) NOT NULL DEFAULT 'normal',
    problem_description     TEXT NOT NULL,
    accessories             TEXT NULL,
    diagnosis               TEXT NULL,
    estimated_price         NUMERIC(12, 2) NULL,
    final_price             NUMERIC(12, 2) NULL,
    hourly_rate             NUMERIC(10, 2) NOT NULL,
    discount_percent        NUMERIC(5, 2) NOT NULL DEFAULT 0,
    vat_rate                NUMERIC(5, 2) NOT NULL,
    received_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
    due_date                DATE NULL,
    completed_at            TIMESTAMPTZ NULL,
    handed_over_at          TIMESTAMPTZ NULL,
    cancelled_at            TIMESTAMPTZ NULL,
    cancellation_reason     TEXT NULL,
    internal_note           TEXT NULL,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT service_orders_number_unique UNIQUE (order_number),
    CONSTRAINT service_orders_number_format CHECK (order_number ~ '^SRV-[0-9]{4}-[0-9]{4,}$'),
    CONSTRAINT service_orders_device_fk FOREIGN KEY (device_id, customer_id)
        REFERENCES devices (id, customer_id) ON DELETE RESTRICT,
    CONSTRAINT service_orders_status_check CHECK (status IN (
        'received', 'diagnostics', 'awaiting_approval', 'in_repair',
        'awaiting_parts', 'completed', 'handed_over', 'cancelled'
    )),
    CONSTRAINT service_orders_priority_check CHECK (priority IN ('low', 'normal', 'high', 'urgent')),
    CONSTRAINT service_orders_prices_check CHECK (
        (estimated_price IS NULL OR estimated_price >= 0)
        AND (final_price IS NULL OR final_price >= 0)
        AND hourly_rate >= 0
    ),
    CONSTRAINT service_orders_discount_check CHECK (discount_percent BETWEEN 0 AND 100),
    CONSTRAINT service_orders_vat_check CHECK (vat_rate BETWEEN 0 AND 100),
    CONSTRAINT service_orders_completed_check CHECK (
        status NOT IN ('completed', 'handed_over') OR completed_at IS NOT NULL
    ),
    CONSTRAINT service_orders_handed_over_check CHECK (status <> 'handed_over' OR handed_over_at IS NOT NULL),
    CONSTRAINT service_orders_cancelled_check CHECK (
        status <> 'cancelled' OR (cancelled_at IS NOT NULL AND cancellation_reason IS NOT NULL)
    )
);
CREATE INDEX service_orders_status_idx ON service_orders (status);
CREATE INDEX service_orders_customer_idx ON service_orders (customer_id);
CREATE INDEX service_orders_device_idx ON service_orders (device_id);
CREATE INDEX service_orders_technician_idx ON service_orders (assigned_technician_id, status);
CREATE INDEX service_orders_received_at_idx ON service_orders (received_at DESC);
CREATE INDEX service_orders_completed_at_idx ON service_orders (completed_at) WHERE completed_at IS NOT NULL;
CREATE INDEX service_orders_due_date_idx ON service_orders (due_date)
    WHERE status NOT IN ('completed', 'handed_over', 'cancelled');
CREATE INDEX service_orders_problem_trgm_idx ON service_orders USING gin (problem_description gin_trgm_ops);
CREATE TRIGGER service_orders_updated_at BEFORE UPDATE ON service_orders FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- ---------------------------------------------------------------------------
-- Historie stavů zakázek
-- ---------------------------------------------------------------------------
CREATE TABLE service_order_status_history (
    id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    service_order_id  BIGINT NOT NULL REFERENCES service_orders (id) ON DELETE CASCADE,
    from_status       VARCHAR(24) NULL,
    to_status         VARCHAR(24) NOT NULL,
    changed_by_id     BIGINT NULL REFERENCES users (id) ON DELETE SET NULL,
    note              TEXT NULL,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX status_history_order_idx ON service_order_status_history (service_order_id, created_at);
CREATE INDEX status_history_created_idx ON service_order_status_history (created_at DESC);

-- ---------------------------------------------------------------------------
-- Interní komentáře
-- ---------------------------------------------------------------------------
CREATE TABLE service_order_comments (
    id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    service_order_id  BIGINT NOT NULL REFERENCES service_orders (id) ON DELETE CASCADE,
    user_id           BIGINT NULL REFERENCES users (id) ON DELETE SET NULL,
    body              TEXT NOT NULL CHECK (length(trim(body)) > 0),
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX comments_order_idx ON service_order_comments (service_order_id, created_at);

-- ---------------------------------------------------------------------------
-- Pracovní výkazy (úkony)
-- ---------------------------------------------------------------------------
CREATE TABLE work_logs (
    id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    service_order_id  BIGINT NOT NULL REFERENCES service_orders (id) ON DELETE CASCADE,
    user_id           BIGINT NULL REFERENCES users (id) ON DELETE SET NULL,
    description       VARCHAR(500) NOT NULL,
    work_date         DATE NOT NULL DEFAULT CURRENT_DATE,
    minutes           INTEGER NOT NULL,
    hourly_rate       NUMERIC(10, 2) NOT NULL,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT work_logs_minutes_check CHECK (minutes > 0 AND minutes <= 1440),
    CONSTRAINT work_logs_rate_check CHECK (hourly_rate >= 0)
);
CREATE INDEX work_logs_order_idx ON work_logs (service_order_id);
CREATE INDEX work_logs_user_date_idx ON work_logs (user_id, work_date);

-- ---------------------------------------------------------------------------
-- Skladové položky
-- ---------------------------------------------------------------------------
CREATE TABLE inventory_items (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    sku             VARCHAR(40) NOT NULL,
    name            VARCHAR(200) NOT NULL,
    description     TEXT NULL,
    unit            VARCHAR(10) NOT NULL DEFAULT 'ks',
    purchase_price  NUMERIC(12, 2) NOT NULL,
    sale_price      NUMERIC(12, 2) NOT NULL,
    quantity        INTEGER NOT NULL DEFAULT 0,
    min_quantity    INTEGER NOT NULL DEFAULT 0,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT inventory_items_sku_unique UNIQUE (sku),
    -- poslední pojistka proti zápornému skladu (aplikační vrstva navíc zamyká řádek)
    CONSTRAINT inventory_items_quantity_check CHECK (quantity >= 0),
    CONSTRAINT inventory_items_min_quantity_check CHECK (min_quantity >= 0),
    CONSTRAINT inventory_items_prices_check CHECK (purchase_price >= 0 AND sale_price >= 0)
);
CREATE INDEX inventory_items_name_trgm_idx ON inventory_items USING gin (name gin_trgm_ops);
CREATE INDEX inventory_items_low_stock_idx ON inventory_items (id) WHERE is_active AND quantity <= min_quantity;
CREATE TRIGGER inventory_items_updated_at BEFORE UPDATE ON inventory_items FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- ---------------------------------------------------------------------------
-- Skladové pohyby
-- ---------------------------------------------------------------------------
CREATE TABLE stock_movements (
    id                 BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    inventory_item_id  BIGINT NOT NULL REFERENCES inventory_items (id) ON DELETE RESTRICT,
    type               VARCHAR(12) NOT NULL,
    quantity           INTEGER NOT NULL,
    quantity_after     INTEGER NOT NULL,
    unit_price         NUMERIC(12, 2) NULL,
    service_order_id   BIGINT NULL REFERENCES service_orders (id) ON DELETE SET NULL,
    user_id            BIGINT NULL REFERENCES users (id) ON DELETE SET NULL,
    note               VARCHAR(500) NULL,
    created_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT stock_movements_type_check CHECK (type IN ('receipt', 'issue', 'return', 'adjustment')),
    CONSTRAINT stock_movements_quantity_check CHECK (quantity <> 0),
    CONSTRAINT stock_movements_sign_check CHECK (
        (type IN ('receipt', 'return') AND quantity > 0)
        OR (type = 'issue' AND quantity < 0)
        OR type = 'adjustment'
    ),
    CONSTRAINT stock_movements_after_check CHECK (quantity_after >= 0)
);
CREATE INDEX stock_movements_item_idx ON stock_movements (inventory_item_id, created_at DESC);
CREATE INDEX stock_movements_order_idx ON stock_movements (service_order_id) WHERE service_order_id IS NOT NULL;

-- ---------------------------------------------------------------------------
-- Materiál spotřebovaný na zakázce (s historickými cenami)
-- ---------------------------------------------------------------------------
CREATE TABLE service_order_materials (
    id                   BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    service_order_id     BIGINT NOT NULL REFERENCES service_orders (id) ON DELETE CASCADE,
    inventory_item_id    BIGINT NOT NULL REFERENCES inventory_items (id) ON DELETE RESTRICT,
    stock_movement_id    BIGINT NULL REFERENCES stock_movements (id) ON DELETE SET NULL,
    sku                  VARCHAR(40) NOT NULL,
    name                 VARCHAR(200) NOT NULL,
    unit                 VARCHAR(10) NOT NULL,
    quantity             INTEGER NOT NULL,
    unit_price           NUMERIC(12, 2) NOT NULL,
    unit_purchase_price  NUMERIC(12, 2) NOT NULL,
    created_by_id        BIGINT NULL REFERENCES users (id) ON DELETE SET NULL,
    created_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT order_materials_quantity_check CHECK (quantity > 0),
    CONSTRAINT order_materials_price_check CHECK (unit_price >= 0 AND unit_purchase_price >= 0)
);
CREATE INDEX order_materials_order_idx ON service_order_materials (service_order_id);
CREATE INDEX order_materials_item_idx ON service_order_materials (inventory_item_id);

-- ---------------------------------------------------------------------------
-- Auditní log
-- ---------------------------------------------------------------------------
CREATE TABLE audit_logs (
    id           BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id      BIGINT NULL REFERENCES users (id) ON DELETE SET NULL,
    action       VARCHAR(64) NOT NULL,
    entity_type  VARCHAR(64) NOT NULL,
    entity_id    BIGINT NULL,
    changes      JSONB NULL,
    ip_address   VARCHAR(45) NULL,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX audit_logs_entity_idx ON audit_logs (entity_type, entity_id);
CREATE INDEX audit_logs_created_idx ON audit_logs (created_at DESC);
CREATE INDEX audit_logs_user_idx ON audit_logs (user_id);
