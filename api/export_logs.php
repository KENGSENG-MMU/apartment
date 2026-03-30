<?php
// 文件路径: api/export_logs.php
require_once '../core/security.php';

// 【安全防御】只有 Admin 和 SuperAdmin 有权限导出敏感的门禁日志
require_login(['admin', 'superadmin']);

// 1. 设置 HTTP 头，告诉浏览器这是一个 CSV 附件下载
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=SmartVMS_GateLogs_' . date('Y-m-d') . '.csv');

// 打开输出流
$output = fopen('php://output', 'w');

// 2. 写入 UTF-8 BOM 头 (完美解决微软 Excel 打开 CSV 时中文或特殊符号乱码的问题)
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// 3. 写入 CSV 表头
fputcsv($output, [
    'Log ID', 
    'Timestamp', 
    'Plate Number', 
    'Action (IN/OUT)', 
    'Decision', 
    'System Reason', 
    'Visitor Name', 
    'Resident Visited'
]);

$pdo = db();

// 4. 从数据库联合查询完整数据
$stmt = $pdo->query("
    SELECT g.id, g.action_time, g.plate_no, g.gate_action, g.decision, g.reason, 
           b.visitor_name, u.email as resident_email
    FROM gate_logs g 
    LEFT JOIN bookings b ON g.booking_id = b.id 
    LEFT JOIN users u ON b.resident_id = u.id
    ORDER BY g.action_time DESC
");

// 5. 循环写入每一行数据
while ($row = $stmt->fetch()) {
    // 格式化数据
    $resident_unit = $row['resident_email'] ? explode('@', $row['resident_email'])[0] : 'N/A';
    $visitor = $row['visitor_name'] ?: 'Walk-in / Unknown';
    
    fputcsv($output, [
        $row['id'],
        $row['action_time'],
        $row['plate_no'],
        $row['gate_action'],
        $row['decision'],
        $row['reason'],
        $visitor,
        $resident_unit
    ]);
}

// 6. 写入完毕，记录一条审计日志 (追踪谁导出了数据)
log_audit('EXPORT_CSV', 'Admin exported gate logs to CSV');

fclose($output);
exit();
?>