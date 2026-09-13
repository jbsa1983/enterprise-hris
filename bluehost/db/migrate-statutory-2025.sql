-- 2025 Philippine statutory update — SSS only (the others were already current).
-- Uses effective-dating: 2024 payroll keeps the old SSS rate, 2025+ uses the new
-- one. Safe/idempotent to run more than once.
--
-- SSS 2025 (RA 11199 final step): total rate 15% → employee 5%, employer 10%.
--   Monthly Salary Credit range ₱5,000 (floor) to ₱35,000 (cap).
--   (2024 was: employee 4.5%, cap ₱30,000.)
-- PhilHealth (5% total, EE 2.5%, ₱10k–₱100k), Pag-IBIG (2%, max ₱200) and the
-- BIR TRAIN monthly withholding brackets are unchanged for 2025 — left as they are.

-- 1) Close the open-ended (2024) SSS rule at the end of 2024.
UPDATE statutory_rule_sets
   SET effective_to = '2024-12-31'
 WHERE rule_name = 'SSS' AND effective_from <= '2024-12-31' AND effective_to IS NULL;

-- 2) Add the 2025 SSS rule (only if it isn't already there).
INSERT INTO statutory_rule_sets
    (rule_name, rule_version, effective_from, effective_to, is_prototype_data, parameters_json, notes)
SELECT 'SSS', '2025.1', '2025-01-01', NULL, 0,
       '{"employee_rate":0.05,"employer_rate":0.10,"msc_floor":5000,"msc_cap":35000}',
       'SSS 2025: 15% total (EE 5%, ER 10%), MSC 5,000-35,000. Simplified continuous MSC (not the 250-wide bracket table).'
 WHERE NOT EXISTS (
       SELECT 1 FROM statutory_rule_sets WHERE rule_name = 'SSS' AND effective_from = '2025-01-01');
