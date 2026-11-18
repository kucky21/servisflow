-- Výchozí nastavení aplikace (lze změnit v administraci)
INSERT INTO settings (key, value) VALUES
    ('company_name', 'ServisHub s.r.o.'),
    ('company_street', 'Technická 1250/12'),
    ('company_city', 'Brno'),
    ('company_zip', '612 00'),
    ('company_ico', '12345679'),
    ('company_dic', 'CZ12345679'),
    ('company_phone', '+420 541 000 111'),
    ('company_email', 'servis@servishub.local'),
    ('company_web', 'www.servishub.local'),
    ('company_bank_account', '123456789/0100'),
    ('default_hourly_rate', '890.00'),
    ('vat_rate', '21.00'),
    ('default_due_days', '5'),
    ('document_footer', 'Zařízení nevyzvednuté do 90 dnů od výzvy k vyzvednutí může být zlikvidováno dle obchodních podmínek.')
ON CONFLICT (key) DO NOTHING;
