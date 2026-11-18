// Builds the schema for the seeder. database.sql names no database and drops nothing, so the seeder drops the DTS
// tables itself (the list is read from database.sql), after its seed guard and the checks below allowed it.
const mysql = require('mysql2/promise');

// Schemas of the server itself: never rebuilt, not even with --force
const SYSTEM_SCHEMAS = ['mysql', 'sys', 'information_schema', 'performance_schema'];

// Tables older versions created and database.sql no longer does; a rebuild removes copies left by them
const LEGACY_TABLES = ['job_batches', 'password_reset_tokens', 'sessions', 'prediction_keywords'];

/**
 * The tables this app owns: every CREATE TABLE in database.sql plus the legacy names. Read from the file, so the
 * list cannot drift from the schema.
 *
 * @param {string} schemaSql contents of database.sql
 * @returns {string[]}
 */
function dtsTableNames(schemaSql) {
    // Comment lines never define a table
    const statements = schemaSql.split('\n').filter((line) => !line.trim().startsWith('--')).join('\n');
    const created = [...statements.matchAll(/CREATE\s+TABLE\s+`?([A-Za-z0-9_]+)`?/gi)].map((match) => match[1]);
    return [...new Set([...created, ...LEGACY_TABLES])];
}

/**
 * Decide whether the rebuild may drop tables in this database.
 *
 * @param {string}   databaseName   DB_DATABASE
 * @param {string[]} existingTables tables the database holds now (empty when it does not exist yet)
 * @param {string}   schemaSql      contents of database.sql
 * @param {boolean}  force          --force was passed
 * @returns {string|null} the reason for refusing, or null to go ahead
 */
function rebuildRefusal(databaseName, existingTables, schemaSql, force) {
    // A server schema is refused unconditionally
    if (SYSTEM_SCHEMAS.includes(String(databaseName).toLowerCase())) {
        return `${databaseName} is a system schema of the database server; it is never rebuilt, not even with --force.`;
    }

    // Tables that are not the app's mean the database belongs to something else, or to more than the app
    const owned = dtsTableNames(schemaSql).map((name) => name.toLowerCase());
    const foreign = existingTables.filter((name) => !owned.includes(name.toLowerCase()));
    if (foreign.length === 0 || force) {
        return null;
    }

    return [
        `${databaseName} holds tables that are not part of this app: ${foreign.join(', ')}.`,
        'Check DB_DATABASE. If it is right, run again with --force: only this app\'s tables are dropped and these are kept.',
    ].join('\n');
}

/**
 * Names of the tables in a database, or an empty list when it does not exist.
 */
async function listTables(connection, databaseName) {
    const [rows] = await connection.query('SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?', [databaseName]);
    return rows.map((row) => row.name);
}

/**
 * Create the database if it is missing, drop the app's tables in it, select it, and run the schema file.
 *
 * Throws, before anything is dropped, when rebuildRefusal() says no. Other tables in the database are never touched.
 * Names are quoted as identifiers, never pasted into the SQL text. The connection must allow multiple statements.
 *
 * @param {object}  connection   a mysql2 promise connection with multipleStatements enabled
 * @param {string}  databaseName the database to build in (DB_DATABASE)
 * @param {string}  schemaSql    contents of database.sql
 * @param {boolean} force        --force was passed
 */
async function rebuildSchema(connection, databaseName, schemaSql, force = false) {
    // Refuse before the first change
    const refusal = rebuildRefusal(databaseName, await listTables(connection, databaseName), schemaSql, force);
    if (refusal !== null) {
        throw new Error(refusal);
    }

    // utf8mb4 matches the character set of the test database
    const quotedName = mysql.escapeId(databaseName);
    await connection.query(`CREATE DATABASE IF NOT EXISTS ${quotedName} CHARACTER SET utf8mb4`);
    await connection.query(`USE ${quotedName}`);

    // Drop only the app's tables that exist, with foreign key checks off for this session and on again afterwards
    const owned = dtsTableNames(schemaSql).map((name) => name.toLowerCase());
    const present = (await listTables(connection, databaseName)).filter((name) => owned.includes(name.toLowerCase()));
    if (present.length > 0) {
        await connection.query('SET FOREIGN_KEY_CHECKS = 0');
        try {
            await connection.query(`DROP TABLE ${present.map((name) => mysql.escapeId(name)).join(', ')}`);
        } finally {
            await connection.query('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    await connection.query(schemaSql);
}

module.exports = { rebuildSchema, rebuildRefusal, listTables, dtsTableNames };
