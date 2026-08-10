<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$outputPath = $root.DIRECTORY_SEPARATOR.'docs'.DIRECTORY_SEPARATOR.'FILE_SCOPE_ALL_FILES.md';
$folders = ['backend', 'frontend', 'database', 'docs', 'scripts', 'docker'];
$groups = ['root' => []];

foreach (new DirectoryIterator($root) as $entry) {
    if ($entry->isFile()) {
        $groups['root'][] = $entry->getPathname();
    }
}

foreach ($folders as $folder) {
    $groups[$folder] = [];
    $directory = $root.DIRECTORY_SEPARATOR.$folder;
    if (! is_dir($directory)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $groups[$folder][] = $file->getPathname();
        }
    }
}

foreach ($groups as &$files) {
    usort($files, static fn (string $left, string $right): int => strnatcasecmp($left, $right));
}
unset($files);

$relativePath = static function (string $path) use ($root): string {
    return str_replace('\\', '/', substr($path, strlen($root) + 1));
};

$isImportant = static function (string $path): bool {
    $normalized = str_replace('\\', '/', $path);

    if (in_array($normalized, [
        'README.md',
        'Dockerfile',
        'render.yaml',
        'backend/.env.example',
        'backend/composer.json',
        'backend/requirements-budget.txt',
        'backend/requirements-budget-local-ai.txt',
        'backend/bootstrap/app.php',
        'backend/config/services.php',
        'frontend/package.json',
        'frontend/vite.config.ts',
        'frontend/src/App.vue',
        'frontend/src/main.ts',
        'frontend/src/style.css',
        'scripts/generate-file-scope.php',
        'scripts/local-api-router.php',
        'scripts/start-local.ps1',
        'scripts/stop-local.ps1',
        'docker/entrypoint.sh',
        'docker/nginx.conf.template',
        'docker/supervisord.conf',
    ], true)) {
        return true;
    }

    return preg_match(
        '#^(backend/app/.+\.php|backend/routes/.+\.php|backend/database/(migrations|seeders)/.+\.php|backend/scripts/.+\.py|frontend/src/.+\.(vue|ts|css)|database/.+\.(sql|md)|docs/.+\.md)$#',
        $normalized
    ) === 1;
};

$describe = static function (string $path): string {
    $normalized = str_replace('\\', '/', $path);
    $name = basename($normalized);
    $stem = pathinfo($name, PATHINFO_FILENAME);
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    if ($normalized === '.gitignore') return 'กำหนดไฟล์และโฟลเดอร์ที่ Git ไม่ต้องติดตาม';
    if ($normalized === 'README.md') return 'คู่มือภาพรวม การติดตั้ง และการเปิดใช้งานโปรเจกต์';
    if ($normalized === 'Dockerfile') return '⭐ Image หลักสำหรับ build Vue, Laravel, PHP, Nginx และ Python Worker บน Render';
    if ($normalized === 'render.yaml') return '⭐ Blueprint กำหนด Render Web Service และ Environment Variables';
    if ($normalized === '.dockerignore') return 'กำหนดไฟล์ที่ไม่ส่งเข้า Docker build เพื่อป้องกัน secret และลดขนาด image';
    if ($normalized === 'backend/requirements-budget.txt') return '⭐ รายการ Python dependencies ของ Budget Worker และ Receipt OCR';
    if ($normalized === 'backend/requirements-budget-local-ai.txt') return '⭐ Python dependencies เพิ่มเติมสำหรับ Sentence Transformer บนเครื่อง Local';
    if ($normalized === 'docker/entrypoint.sh') return '⭐ เตรียม Laravel/Nginx แล้วเริ่มทุก Process ใน Container';
    if ($normalized === 'docker/nginx.conf.template') return '⭐ ตั้งค่า Nginx ให้ Vue และ Laravel API ใช้ domain เดียวกัน';
    if ($normalized === 'docker/supervisord.conf') return '⭐ ดูแล PHP-FPM, Nginx และ Budget Worker ให้ทำงานพร้อมกัน';
    if (str_starts_with($normalized, 'backend/vendor/')) return 'ไฟล์ dependency PHP จาก Composer; ไม่ควรแก้โดยตรงและสร้างใหม่ได้จาก composer install';
    if (str_starts_with($normalized, 'frontend/node_modules/')) return 'ไฟล์ dependency Frontend จาก pnpm; ไม่ควรแก้โดยตรงและสร้างใหม่ได้จาก pnpm install';
    if (str_starts_with($normalized, 'frontend/dist/')) return 'ไฟล์ผลลัพธ์ Production build ของ Vue; สร้างใหม่ด้วย pnpm build';
    if (str_contains($normalized, '/__pycache__/') || $extension === 'pyc') return 'Python bytecode cache ที่สร้างอัตโนมัติ; ลบและสร้างใหม่ได้';
    if (str_starts_with($normalized, 'backend/storage/framework/cache/')) return 'Laravel runtime cache ที่สร้างอัตโนมัติ';
    if (str_starts_with($normalized, 'backend/storage/framework/sessions/')) return 'ไฟล์ session ของ Laravel ที่สร้างระหว่างการเข้าสู่ระบบ';
    if (str_starts_with($normalized, 'backend/storage/logs/')) return 'Log การทำงานและข้อผิดพลาดของ Laravel';
    if (str_starts_with($normalized, 'backend/storage/app/private/budget/')) return 'ไฟล์งานวิเคราะห์งบประมาณส่วนตัวของผู้ใช้ที่ระบบสร้างระหว่างทำงาน';
    if (preg_match('#^backend/app/Http/Controllers/Api/(.+)Controller\.php$#', $normalized, $match)) return "API Controller {$match[1]} รับคำขอ ตรวจสิทธิ์ และส่งผลลัพธ์ให้ Frontend";
    if (preg_match('#^backend/app/Http/Middleware/(.+)\.php$#', $normalized, $match)) return "Middleware {$match[1]} ตรวจหรือปรับ request ก่อนเข้า Controller";
    if (preg_match('#^backend/app/Models/(.+)\.php$#', $normalized, $match)) return "Eloquent Model {$match[1]} สำหรับอ่านและเขียนข้อมูลใน PostgreSQL";
    if (preg_match('#^backend/app/Services/(.+)\.php$#', $normalized, $match)) return "Service {$match[1]} รวม business logic ที่ใช้ซ้ำใน Backend";
    if (str_starts_with($normalized, 'backend/tests/')) return "ชุดทดสอบอัตโนมัติ {$stem} สำหรับป้องกัน regression ของ Backend";
    if (str_starts_with($normalized, 'backend/config/')) return "ไฟล์ตั้งค่า Laravel หมวด {$stem}";
    if (str_starts_with($normalized, 'backend/routes/')) return "ประกาศเส้นทาง {$stem} ของ Laravel";
    if (str_starts_with($normalized, 'backend/scripts/') && $extension === 'py') return "สคริปต์ Python {$stem} สำหรับงานวิเคราะห์ข้อมูล/AI ของ Backend";
    if ($normalized === 'backend/artisan') return 'CLI entry point ของ Laravel สำหรับคำสั่งระบบและการทดสอบ';
    if ($normalized === 'backend/composer.json') return 'กำหนด package, autoload และคำสั่ง Composer ของ Backend';
    if ($normalized === 'backend/composer.lock') return 'ล็อกเวอร์ชัน dependency PHP ให้ติดตั้งได้เหมือนกันทุกเครื่อง';
    if ($normalized === 'backend/phpunit.xml') return 'ตั้งค่าสภาพแวดล้อมสำหรับ PHPUnit';
    if ($normalized === 'backend/.env') return 'ค่าตั้งค่าจริงของ Backend ในเครื่องนี้; มีข้อมูลลับและไม่ควร commit';
    if ($normalized === 'backend/.env.example') return 'แม่แบบ environment variables ของ Backend โดยไม่มีข้อมูลลับ';
    if (preg_match('#^frontend/src/views/(.+)View\.vue$#', $normalized, $match)) return "หน้าจอ Vue {$match[1]} ที่ผูก UI กับ API ของฟังก์ชันนั้น";
    if (preg_match('#^frontend/src/components/(.+)\.vue$#', $normalized, $match)) return "Vue Component {$match[1]} ที่นำกลับมาใช้ซ้ำในหลายหน้า";
    if (str_starts_with($normalized, 'frontend/src/api/')) return "โมดูล API {$stem} สำหรับสื่อสารกับ Laravel";
    if (str_starts_with($normalized, 'frontend/src/router/')) return 'กำหนด Vue Router, เส้นทางหน้าเว็บ และ route guard';
    if (str_starts_with($normalized, 'frontend/src/stores/')) return "Pinia store {$stem} สำหรับ state ที่ใช้ร่วมกัน";
    if (str_starts_with($normalized, 'frontend/src/types/')) return "TypeScript types {$stem} ที่กำหนดรูปแบบข้อมูลร่วมกัน";
    if (str_starts_with($normalized, 'frontend/src/utils/')) return "Utility {$stem} สำหรับฟังก์ชันช่วยที่ใช้ซ้ำใน Frontend";
    if ($normalized === 'frontend/src/style.css') return 'CSS หลักและ responsive styles ของหน้าเว็บทั้งหมด';
    if ($normalized === 'frontend/src/main.ts') return 'จุดเริ่มต้น Vue ที่ติดตั้ง router/store และ mount แอป';
    if ($normalized === 'frontend/src/App.vue') return 'Vue root component ของแอปพลิเคชัน';
    if (str_starts_with($normalized, 'frontend/public/')) return 'Static asset ที่ Vite คัดลอกไปใช้โดยไม่ประมวลผล';
    if ($normalized === 'frontend/package.json') return 'กำหนด dependency และคำสั่ง build/test ของ Frontend';
    if ($normalized === 'frontend/pnpm-lock.yaml') return 'ล็อกเวอร์ชัน dependency Frontend สำหรับ pnpm';
    if (str_starts_with($normalized, 'frontend/') && str_contains($name, 'config')) return "ไฟล์ตั้งค่า {$stem} ของเครื่องมือ Frontend";
    if (str_starts_with($normalized, 'database/') && $extension === 'sql') return "SQL {$stem} สำหรับสร้างหรือปรับ schema/data ใน PostgreSQL";
    if ($normalized === 'docs/FILE_SCOPE_ALL_FILES.md') return 'สารบัญหน้าที่ทุกไฟล์ในโปรเจกต์ที่สร้างจากสคริปต์อัตโนมัติ';
    if (str_starts_with($normalized, 'docs/') && $extension === 'md') return "เอกสาร {$stem} สำหรับอธิบายระบบหรือขั้นตอนการทำงาน";
    if (str_starts_with($normalized, 'scripts/') && $extension === 'ps1') return "PowerShell script {$stem} สำหรับเปิด ปิด หรือดูแลงาน Local prototype";
    if ($normalized === 'scripts/generate-file-scope.php') return 'สคริปต์สร้างเอกสารรายการหน้าที่ทุกไฟล์และตรวจจำนวนให้ตรงกับไฟล์จริง';
    if (str_starts_with($normalized, 'scripts/') && $extension === 'php') return "PHP utility script {$stem} สำหรับงานดูแลโปรเจกต์";

    return match ($extension) {
        'php' => "ไฟล์ PHP {$stem} ที่เป็นส่วนหนึ่งของ Backend",
        'vue' => "Vue Single-File Component {$stem}",
        'ts', 'tsx' => "โมดูล TypeScript {$stem} ของ Frontend",
        'js', 'mjs', 'cjs' => "โมดูล JavaScript {$stem}",
        'css', 'scss' => "ไฟล์ style {$stem} สำหรับการแสดงผล",
        'json', 'yaml', 'yml', 'toml', 'xml' => "ไฟล์ configuration/metadata {$name}",
        'md', 'txt' => "ไฟล์เอกสารหรือข้อความ {$name}",
        'sql' => "ไฟล์คำสั่งฐานข้อมูล {$name}",
        'py' => "สคริปต์ Python {$stem}",
        'xlsx', 'csv' => "ไฟล์ข้อมูลตาราง {$name}",
        'png', 'jpg', 'jpeg', 'svg', 'ico', 'webp' => "ไฟล์รูปภาพ/asset {$name}",
        'woff', 'woff2', 'ttf', 'otf' => "ไฟล์แบบอักษร {$name}",
        'lock' => "ไฟล์ล็อกเวอร์ชัน dependency {$name}",
        default => "ไฟล์ประกอบระบบ {$name}",
    };
};

$counts = array_map('count', $groups);
$total = array_sum($counts);
$importantTotal = 0;
foreach ($groups as $files) {
    foreach ($files as $absolutePath) {
        if ($isImportant($relativePath($absolutePath))) {
            $importantTotal++;
        }
    }
}
$generatedAt = date('c');
$folderCounts = implode(', ', array_map(
    static fn (string $group): string => "{$group} {$counts[$group]}",
    array_keys($counts),
));
$lines = [
    '# หน้าที่ของทุกไฟล์ใน Valor ERP',
    '',
    "สร้างอัตโนมัติเมื่อ {$generatedAt} โดย `scripts/generate-file-scope.php`",
    '',
    "รวมทั้งหมด **{$total} ไฟล์**: {$folderCounts}",
    '',
    '> รายการนี้รวม `backend/vendor`, `frontend/node_modules`, build output, cache, log และไฟล์ runtime เพื่อให้ครบทุกไฟล์จริงในโฟลเดอร์ที่ขอ แต่ไฟล์ dependency/generated ระบุไว้ชัดว่าไม่ควรแก้โดยตรง ส่วน `.git`, `.tools`, `.runtime`, `.vscode` อยู่นอกห้าโฟลเดอร์งานและไม่ใช่ source scope จึงไม่รวม',
];

$lines[] = '';
$lines[] = "⭐ = ไฟล์หลักที่มักต้องแก้โดยตรงเมื่อพัฒนาระบบ (รวม {$importantTotal} ไฟล์) — ค้นหาคำว่า `⭐` เพื่อย่อขอบเขตงาน";

foreach ($groups as $group => $files) {
    $lines[] = '';
    $lines[] = '## '.($group === 'root' ? 'ไฟล์รากโปรเจกต์' : $group)." ({$counts[$group]} ไฟล์)";
    $lines[] = '';
    foreach ($files as $absolutePath) {
        $relative = $relativePath($absolutePath);
        $safePath = str_replace('`', '\\`', $relative);
        $marker = $isImportant($relative) ? '⭐ ' : '';
        $lines[] = "- {$marker}`{$safePath}` — ".$describe($relative);
    }
}

file_put_contents($outputPath, implode(PHP_EOL, $lines).PHP_EOL);
echo "Generated {$outputPath} with {$total} files".PHP_EOL;
