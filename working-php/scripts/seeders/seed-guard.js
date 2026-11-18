// Guard for the production seeder: rebuilding a database deletes everything in it, so a database that
// already holds documents or accounts is only rebuilt when the operator passes --force.

/**
 * Decide whether the seeder may rebuild the database.
 *
 * @param {number} existingDocumentCount rows in documents (0 when the table does not exist)
 * @param {number} existingUserCount     rows in users (0 when the table does not exist)
 * @param {string[]} argv                the process arguments
 * @returns {string|null} the reason for refusing, or null to go ahead
 */
function refusalMessage(existingDocumentCount, existingUserCount, argv) {
    // An empty or missing database has nothing to lose
    if (existingDocumentCount === 0 && existingUserCount === 0) {
        return null;
    }

    // The operator has confirmed that the data may be deleted
    if (argv.includes('--force')) {
        return null;
    }

    return [
        `The database already holds ${existingDocumentCount} document(s) and ${existingUserCount} user(s).`,
        'Seeding rebuilds every table and deletes all of it.',
        'Make a backup first, then run it again with --force if that is what you want:',
        '   composer run seed:prod -- --force',
    ].join('\n');
}

/**
 * Number of rows in a table, or 0 when the database or the table does not exist yet.
 */
async function countRows(connection, database, table) {
    // Look the table up first, so a missing database is not an error
    const [found] = await connection.query(
        'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
        [database, table]
    );
    if (found.length === 0) {
        return 0;
    }

    // ?? quotes the names as identifiers, so they are never read as SQL
    const [rows] = await connection.query('SELECT COUNT(*) AS c FROM ??.??', [database, table]);
    return Number(rows[0].c);
}

/**
 * Message for the API seeder when the server answers 429 Too Many Requests, or null for any other status.
 *
 * The guest routes are rate limited per IP, and the seeder submits thousands of documents from one IP, so the
 * first 429 means every later request would fail the same way.
 *
 * @param {number} status        HTTP status of the response
 * @param {string|null} retryAfter value of the Retry-After header, if any
 * @returns {string|null}
 */
function rateLimitMessage(status, retryAfter) {
    // Only 429 is a rate-limit answer
    if (status !== 429) {
        return null;
    }

    const wait = retryAfter ? ` (the server asks for ${retryAfter} s between bursts)` : '';
    return [
        `The server refused a request with HTTP 429 Too Many Requests${wait}.`,
        'The public routes limit each IP address, and the seeder sends far more requests than that from one IP.',
        'Set RATE_LIMIT_ENABLED=false in the server\'s .env, restart PHP-FPM, run the seeder, then set it back to true.',
        'Never leave rate limiting off on a server that real users reach.',
    ].join('\n');
}

module.exports = { refusalMessage, countRows, rateLimitMessage };
