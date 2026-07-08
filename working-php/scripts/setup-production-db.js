require('dotenv').config();
const mysql = require('mysql2/promise');
const fs = require('fs');
const path = require('path');

async function setupDatabase() {
    let connection;
    try {
        console.log('🔄 Starting Database Setup...');

        connection = await mysql.createConnection({
            host: process.env.DB_HOST || '127.0.0.1',
            user: process.env.DB_USER || 'root',
            password: process.env.DB_PASSWORD || 'One5zero03',
            multipleStatements: true // Required to run the full SQL file
        });

        // 1. Load and execute database.sql
        const sqlFilePath = path.join(__dirname, '../database.sql');
        const sqlContent = fs.readFileSync(sqlFilePath, 'utf8');
        
        console.log('🧹 Dropping existing tables and rebuilding schema (including document_snapshot)...');
        await connection.query(sqlContent);
        
        // Make sure we are using the new DB
        await connection.query(`USE ${process.env.DB_NAME || 'deped_dts'}`);

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
            'School Governance and Operations Division'
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
            "INSERT INTO users (name, email, password, role, created_at, updated_at) VALUES (?, ?, ?, 'admin', NOW(), NOW())",
            ['Admin User', 'admin@dts.com', hashedPassword]
        );

        const [depts] = await connection.query('SELECT id, name FROM departments');
        
        for (const dept of depts) {
            if (dept.name === 'Records Unit') {
                await connection.query(
                    "INSERT INTO users (name, email, password, department_id, role, created_at, updated_at) VALUES (?, ?, ?, ?, 'officer', NOW(), NOW())",
                    ['Records Officer', 'records@dts.com', hashedPassword, dept.id]
                );
            } else {
                // Laravel creates email using Str::slug($department->name, '.') . '@dts.com'
                const slug = dept.name.toLowerCase().replace(/ and /g, '.and.').replace(/ /g, '.');
                const email = `${slug}@dts.com`;
                
                await connection.query(
                    "INSERT INTO users (name, email, password, department_id, role, created_at, updated_at) VALUES (?, ?, ?, ?, 'staff', NOW(), NOW())",
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
