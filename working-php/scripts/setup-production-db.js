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
            ['CAV Request (Certification, Authentication, and Verification)', true],
            ['Request for Certified True Copy (CTC)', true],
            ['Correction of School Entries', true],
            ['Submission of Application Documents (Ranking)', true],
            ['Request for Service Record', true],
            ['Request for Certificate of Employment (COE)', true],
            ['Request for Approval to Conduct Study/Survey', true],
            ['Request for Data/Statistics', true],
            ['Application for Government Permit/Recognition', true],
            ['Filing of Administrative Complaint', true],
            ['Proposal for Partnership (MOA/MOU)', true],
            ['Processing of First Salary', true],
            ['Retirement Claims', true],
            ['Request for Use of Division Facilities/Vehicle', true],
            ['Request for Office Supplies/Equipment (RIS)', true],
            ['Submission of Bid Documents (Project Tender)', true],
            ['Submission of Nutritional Status Report (SF13)', true],
            ['Request for Learning Resources/Textbooks', true],
            ['Submission of School Monitoring and Evaluation Report', true]
        ];

        for (const [name, isOfficial] of purposes) {
            await connection.query('INSERT INTO purposes (name, is_official, created_at, updated_at) VALUES (?, ?, NOW(), NOW())', [name, isOfficial]);
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
