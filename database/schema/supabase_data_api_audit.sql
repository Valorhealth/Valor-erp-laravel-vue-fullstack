-- Read-only Supabase Data API privilege audit.
-- Run in the Supabase SQL Editor before and after permission migrations.

-- 1. Tables reachable by Data API roles and their RLS state.
SELECT
    n.nspname AS schema_name,
    c.relname AS table_name,
    c.relrowsecurity AS rls_enabled,
    g.grantee,
    string_agg(g.privilege_type, ', ' ORDER BY g.privilege_type) AS privileges
FROM pg_class AS c
JOIN pg_namespace AS n
    ON n.oid = c.relnamespace
JOIN information_schema.role_table_grants AS g
    ON g.table_schema = n.nspname
   AND g.table_name = c.relname
WHERE n.nspname = 'public'
  AND c.relkind IN ('r', 'p')
  AND g.grantee IN ('anon', 'authenticated', 'service_role')
GROUP BY n.nspname, c.relname, c.relrowsecurity, g.grantee
ORDER BY c.relname, g.grantee;

-- 2. High-risk result: client roles can access a table that has no RLS.
SELECT DISTINCT
    g.table_schema,
    g.table_name,
    g.grantee,
    g.privilege_type
FROM information_schema.role_table_grants AS g
JOIN pg_class AS c
    ON c.relname = g.table_name
JOIN pg_namespace AS n
    ON n.oid = c.relnamespace
   AND n.nspname = g.table_schema
WHERE g.table_schema = 'public'
  AND g.grantee IN ('anon', 'authenticated')
  AND g.privilege_type IN ('SELECT', 'INSERT', 'UPDATE', 'DELETE')
  AND NOT c.relrowsecurity
ORDER BY g.table_name, g.grantee, g.privilege_type;

-- 3. Default privileges that will be applied to future objects created by
-- postgres. An empty result for anon/authenticated/service_role tables and
-- sequences is expected for this backend-only project.
SELECT
    owner_role.rolname AS object_owner,
    namespace.nspname AS schema_name,
    defaults.defaclobjtype AS object_type,
    grantee_role.rolname AS grantee,
    privilege.privilege_type,
    privilege.is_grantable
FROM pg_default_acl AS defaults
JOIN pg_roles AS owner_role
    ON owner_role.oid = defaults.defaclrole
LEFT JOIN pg_namespace AS namespace
    ON namespace.oid = defaults.defaclnamespace
CROSS JOIN LATERAL aclexplode(defaults.defaclacl) AS privilege
LEFT JOIN pg_roles AS grantee_role
    ON grantee_role.oid = privilege.grantee
WHERE owner_role.rolname = 'postgres'
  AND COALESCE(namespace.nspname, 'public') = 'public'
  AND grantee_role.rolname IN ('anon', 'authenticated', 'service_role')
ORDER BY object_type, grantee, privilege_type;
