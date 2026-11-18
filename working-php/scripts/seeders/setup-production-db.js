const path = require('path');
require('dotenv').config({ path: path.join(__dirname, '../../.env') });
const { refusalMessage, countRows } = require('./seed-guard');
const { removeFilesIn } = require('./seed-files');
const { rebuildSchema, rebuildRefusal, listTables } = require('./seed-database');

const mysql = require('mysql2/promise');
const fs = require('fs');

/**
 * Remove this app's session files and cached pages, which belong to the data that is about to be replaced.
 * Only the app's own folders are touched, never the session folder other PHP apps on the server share.
 */
function clearSessionsAndCache() {
    removeFilesIn(path.join(__dirname, '../../storage/sessions'), /^sess_/);
    removeFilesIn(path.join(__dirname, '../../cache/responses'), /./);
}

async function setupDatabase() {
    let connection;
    try {
        console.log('🔄 Starting Database Setup...');

        connection = await mysql.createConnection({
            host: process.env.DB_HOST,
            user: process.env.DB_USERNAME,
            password: process.env.DB_PASSWORD,
            multipleStatements: true, // Required to run the full SQL file
            timezone: '+08:00' // Convert JS dates using Philippine time
        });

        // Run the session on Philippine time so SQL NOW() matches the Asia/Manila times PHP writes
        await connection.query("SET time_zone = '+08:00'");

        // Without a database name there is nothing to check against, so nothing is rebuilt
        if (!process.env.DB_DATABASE) {
            console.error('🛑 DB_DATABASE is not set in .env.');
            await connection.end();
            process.exit(1);
        }

        // 1. Load database.sql
        const sqlFilePath = path.join(__dirname, '../../database.sql');
        const sqlContent = fs.readFileSync(sqlFilePath, 'utf8');

        // Never rebuild a database that already holds documents or accounts unless the operator passed --force.
        // database.sql names no database, so DB_DATABASE (from .env) is the only one that gets rebuilt and the only one checked.
        const existingDocuments = await countRows(connection, process.env.DB_DATABASE, 'documents');
        const existingUsers = await countRows(connection, process.env.DB_DATABASE, 'users');
        const refusal = refusalMessage(existingDocuments, existingUsers, process.argv);
        if (refusal !== null) {
            console.error('🛑 ' + refusal);
            await connection.end();
            process.exit(1);
        }

        // Refuse a system schema always, and a database with tables of other applications unless --force (nothing is changed yet)
        const rebuildRefused = rebuildRefusal(process.env.DB_DATABASE, await listTables(connection, process.env.DB_DATABASE), sqlContent, process.argv.includes('--force'));
        if (rebuildRefused !== null) {
            console.error('🛑 ' + rebuildRefused);
            await connection.end();
            process.exit(1);
        }

        // From here on the rebuild goes ahead: clear this app's sessions and cached pages
        clearSessionsAndCache();

        // Ensure the server Ed25519 key exists; system-initiated log entries cannot be signed without it
        console.log('🔐 Ensuring system signing key exists...');
        require('child_process').execFileSync('php', [path.join(__dirname, '../generate-keys.php'), '--system'], { stdio: 'inherit' });

        console.log('🔐 Ensuring integrity key exists...');
        require('child_process').execFileSync('php', [path.join(__dirname, '../generate-keys.php'), '--integrity'], { stdio: 'inherit' });

        console.log('🧹 Dropping this app's tables, then building the schema (including document_snapshot)...');

        // database.sql names no database and drops nothing: drop the app's tables in DB_DATABASE (the checks above allowed it), select it, build the schema
        await rebuildSchema(connection, process.env.DB_DATABASE, sqlContent, process.argv.includes('--force'));

        console.log('🌱 Seeding foundational data...');

        // 2. Seed Departments
        const departments = [
            'Records Unit',
            'Cash Unit',
            'Administrative Unit',
            'Personnel Unit',
            'Supply Unit',
            'Budget Unit',
            'Accounting Unit',
            'Legal Unit',
            'Health and Nutrition',
            'Bids and Awards Committee Unit',
            'Schools Division Superintendent Office',
            'Assistant Schools Division Superintendent Office',
            'Curriculum Implementation Division',
            'School Governance and Operations Division',
            'ICT Unit'
        ];

        for (const dept of departments) {
            await connection.query('INSERT INTO departments (name, created_at, updated_at) VALUES (?, NOW(), NOW())', [dept]);
        }
        
        // 3. Seed Purposes
        const purposes = [
            {
                name: 'CAV Request (Certification, Authentication, and Verification)',
                is_official: true,
                requirements: ['Letter of Request', 'Certified True Copy of School Records', 'PSA Birth Certificate'],
                suggested_route: ['Records Unit', 'Schools Division Superintendent Office'],
            },
            {
                name: 'Request for Certified True Copy (CTC)',
                is_official: true,
                requirements: ['Letter of Request', 'Valid ID'],
                suggested_route: ['Records Unit'],
            },
            {
                name: 'Correction of School Entries',
                is_official: true,
                requirements: ['Affidavit of Discrepancy', 'PSA Birth Certificate', 'Original School Records'],
                suggested_route: ['Records Unit', 'Legal Unit'],
            },
            {
                name: 'Submission of Application Documents (Ranking)',
                is_official: true,
                requirements: ['Letter of Intent', 'Personal Data Sheet (PDS)', 'CSC Form 212', 'PRC License/ID', 'Transcript of Records'],
                suggested_route: ['Personnel Unit', 'Records Unit'],
            },
            {
                name: 'Request for Service Record',
                is_official: true,
                requirements: ['Letter of Request', 'Valid ID'],
                suggested_route: ['Personnel Unit'],
            },
            {
                name: 'Request for Certificate of Employment (COE)',
                is_official: true,
                requirements: ['Letter of Request', 'Valid ID'],
                suggested_route: ['Personnel Unit'],
            },
            {
                name: 'Request for Approval to Conduct Study/Survey',
                is_official: true,
                requirements: ['Letter of Intent to Conduct Study', 'Research Proposal', 'Survey Questionnaire'],
                suggested_route: ['Records Unit', 'Schools Division Superintendent Office'],
            },
            {
                name: 'Request for Data/Statistics',
                is_official: true,
                requirements: ['Letter of Request specifying data needed', 'Valid ID'],
                suggested_route: ['Records Unit'],
            },
            {
                name: 'Application for Government Permit/Recognition',
                is_official: true,
                requirements: ['Application Letter', 'Feasibility Study', 'School Site and Building Plans', 'List of Faculty and Staff'],
                suggested_route: ['Records Unit', 'Schools Division Superintendent Office'],
            },
            {
                name: 'Filing of Administrative Complaint',
                is_official: true,
                requirements: ['Formal Complaint-Affidavit', 'Supporting Evidence/Documents'],
                suggested_route: ['Legal Unit', 'Schools Division Superintendent Office'],
            },
            {
                name: 'Proposal for Partnership (MOA/MOU)',
                is_official: true,
                requirements: ['Letter of Intent for Partnership', 'Draft Memorandum of Agreement/Understanding'],
                suggested_route: ['Schools Division Superintendent Office'],
            },
            {
                name: 'Processing of First Salary',
                is_official: true,
                requirements: ['Appointment Paper', 'PSIPOP', 'ATM Form'],
                suggested_route: ['Personnel Unit', 'Budget Unit', 'Accounting Unit', 'Cash Unit']
            },
            {
                name: 'Retirement Claims',
                is_official: true,
                requirements: ['Letter of Intent to Retire', 'Service Record'],
                suggested_route: ['Personnel Unit', 'Accounting Unit', 'Schools Division Superintendent Office']
            },
            {
                name: 'Request for Use of Division Facilities/Vehicle',
                is_official: true,
                requirements: ['Letter of Request', 'Approved Itinerary (if vehicle)'],
                suggested_route: ['Administrative Unit', 'Assistant Schools Division Superintendent Office'],
            },
            {
                name: 'Request for Office Supplies/Equipment (RIS)',
                is_official: true,
                requirements: ['Requisition and Issue Slip (RIS)', 'Inventory Custodian Slip (ICS)'],
                suggested_route: ['Supply Unit', 'Administrative Unit'],
            },
            {
                name: 'Submission of Bid Documents (Project Tender)',
                is_official: true,
                requirements: ['Bidding Documents', 'Technical Proposal', 'Financial Proposal'],
                suggested_route: ['Bids and Awards Committee Unit', 'Legal Unit'],
            },
            {
                name: 'Submission of Nutritional Status Report (SF13)',
                is_official: true,
                requirements: ['Nutritional Status Summary', 'School Health Profile'],
                suggested_route: ['Health and Nutrition', 'Curriculum Implementation Division'],
            },
            {
                name: 'Request for Learning Resources/Textbooks',
                is_official: true,
                requirements: ['Inventory of Books', 'Request Form'],
                suggested_route: ['Curriculum Implementation Division'],
            },
            {
                name: 'Submission of School Monitoring and Evaluation Report',
                is_official: true,
                requirements: ['Monitoring Tool Results', 'Analysis Report'],
                suggested_route: ['School Governance and Operations Division', 'Assistant Schools Division Superintendent Office'],
            },
            {
                name: 'Request for Archived Document Retrieval',
                is_official: true,
                requirements: ['Letter of Request', 'Valid ID'],
                suggested_route: ['Records Unit', 'Legal Unit', 'Schools Division Superintendent Office'],
            },
            {
                name: 'Records Authentication and Verification (RAV)',
                is_official: true,
                requirements: ['Original Records', 'Valid ID'],
                suggested_route: ['Records Unit', 'Personnel Unit', 'Administrative Unit'],
            },
            {
                name: 'Disposal of Old Files/Records',
                is_official: true,
                requirements: ['Records Disposition Schedule', 'Inventory of Old Files'],
                suggested_route: ['Records Unit', 'Supply Unit', 'Accounting Unit'],
            },
            {
                name: 'Others',
                is_official: false,
                requirements: [],
                suggested_route: [],
            }
        ];

        for (const purpose of purposes) {
            await connection.query(
                'INSERT INTO purposes (name, is_official, requirements, suggested_route, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())', 
                [purpose.name, purpose.is_official, JSON.stringify(purpose.requirements), JSON.stringify(purpose.suggested_route)]
            );
        }

        // 4. Seed Users
        // PHP password_hash() uses bcrypt. We will use a pre-hashed string for 'password'
        const hashedPassword = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
        
        // Admin User
        await connection.query(
            "INSERT INTO users (name, email, password, must_change_password, role, created_at, updated_at) VALUES (?, ?, ?, 1, 'admin', NOW(), NOW())",
            ['Admin User', 'admin', hashedPassword]
        );

        const [depts] = await connection.query('SELECT id, name FROM departments');
        
        for (const dept of depts) {
            if (dept.name === 'Records Unit') {
                await connection.query(
                    "INSERT INTO users (name, email, password, must_change_password, department_id, role, created_at, updated_at) VALUES (?, ?, ?, 1, ?, 'officer', NOW(), NOW())",
                    ['Records Officer', 'records.unit', hashedPassword, dept.id]
                );
            } else {
                // Generate clean department username slug (e.g., cash.unit, administrative.unit)
                const slug = dept.name.toLowerCase().replace(/ and /g, '.and.').replace(/ /g, '.');
                const email = slug;
                
                await connection.query(
                    "INSERT INTO users (name, email, password, must_change_password, department_id, role, created_at, updated_at) VALUES (?, ?, ?, 1, ?, 'staff', NOW(), NOW())",
                    [`${dept.name} Staff`, email, hashedPassword, dept.id]
                );
            }
        }

        console.log('✅ Foundational data seeded successfully!');
        console.log('🎉 Production Setup Completed! (Run `node seed.js` separately if you want dummy documents).');
        
        process.exit(0);
    } catch (error) {
        console.error('\n❌ Database setup failed:', error);
        process.exit(1);
    } finally {
        if (connection) {
            await connection.end();
        }
    }
}

setupDatabase();
