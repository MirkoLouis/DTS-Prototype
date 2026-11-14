// File cleanup for the seeders, done with the file system API instead of a shell command, so only
// this app's own folders are touched and nothing is interpreted as shell syntax.
const fs = require('fs');
const path = require('path');

/**
 * Delete the regular files directly inside a folder whose names match a pattern.
 *
 * Subfolders and their contents are left alone, and a folder that does not exist is not an error.
 *
 * @param {string} directory folder to clean
 * @param {RegExp} pattern   file names to delete
 * @returns {number} how many files were deleted
 */
function removeFilesIn(directory, pattern) {
    // Nothing to clean
    if (!fs.existsSync(directory)) {
        return 0;
    }

    let removed = 0;
    for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
        // Only matching regular files
        if (!entry.isFile() || !pattern.test(entry.name)) {
            continue;
        }
        fs.rmSync(path.join(directory, entry.name), { force: true });
        removed++;
    }

    return removed;
}

module.exports = { removeFilesIn };
