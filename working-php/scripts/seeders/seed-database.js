// Builds the schema for the seeder. database.sql names no database, so the seeder creates and selects the one
// from DB_DATABASE before it runs the schema file.
const mysql = require('mysql2/promise');

/**
 * Create the database if it is missing, select it, and run the schema file in it.
 *
 * The name is quoted as an identifier, never pasted into the SQL text. The connection must allow multiple statements.
 *
 * @param {object} connection   a mysql2 promise connection with multipleStatements enabled
 * @param {string} databaseName the database to build in (DB_DATABASE)
 * @param {string} schemaSql    contents of database.sql
 */
async function rebuildSchema(connection, databaseName, schemaSql) {
    // utf8mb4 matches the character set of the test database
    const quotedName = mysql.escapeId(databaseName);
    await connection.query(`CREATE DATABASE IF NOT EXISTS ${quotedName} CHARACTER SET utf8mb4`);
    await connection.query(`USE ${quotedName}`);
    await connection.query(schemaSql);
}

module.exports = { rebuildSchema };
