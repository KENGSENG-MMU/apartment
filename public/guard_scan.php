<?php
// 文件路径: public/guard_scan.php
require_once '../core/security.php';

// 【安全防御】限制只有保安和管理员可以访问此页
require_login(['guard', 'admin', 'superadmin']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Guard Scanner - <?= APP_NAME ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        :root { --primary: #4f46e5; --bg: #111827; --surface: #1f2937; --text: #f9fafb; --success: #10b981; --danger: #ef4444; }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Plus Jakarta Sans', sans-serif; }
        /* 保安端使用暗黑模式，更符合户外工作环境，且显得更专业 */
        body { background: var(--bg); color: var(--text); display: flex; flex-direction: column; min-height: 100vh; }
        
        .header { padding: 20px; display: flex; justify-content: space-between; align-items: center; background: var(--surface); border-bottom: 1px solid #374151; }
        .logo { font-size: 1.2rem; font-weight: 700; }
        .logo span { color: var(--primary); }
        .user-badge { background: #374151; padding: 5px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: 600; display: flex; align-items: center; gap: 8px; }
        
        .main-content { flex: 1; padding: 20px; display: flex; flex-direction: column; align-items: center; }
        
        /* 模式切换器 */
        .mode-switch { display: flex; background: #374151; border-radius: 12px; padding: 5px; margin-bottom: 20px; width: 100%; max-width: 300px; }
        .mode-btn { flex: 1; padding: 10px; border-radius: 8px; text-align: center; font-weight: 700; cursor: pointer; transition: 0.3s; color: #9ca3af; border: none; background: transparent; }
        .mode-btn.active { background: var(--primary); color: white; box-shadow: 0 2px 10px rgba(79, 70, 229, 0.4); }

        /* 真实摄像头容器 */
        .camera-container { width: 100%; max-width: 400px; height: 300px; background: #000; border-radius: 16px; position: relative; overflow: hidden; border: 3px solid #374151; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
        #videoElement { width: 100%; height: 100%; object-fit: cover; }
        
        /* 扫描动画框 */
        .scan-overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; }
        .scan-line { width: 100%; height: 3px; background: var(--primary); box-shadow: 0 0 15px 5px rgba(79, 70, 229, 0.4); position: absolute; top: 0; animation: scanAnim 2.5s infinite linear; }
        @keyframes scanAnim { 0% { top: 0; } 50% { top: calc(100% - 3px); } 100% { top: 0; } }
        
        .btn-capture { margin-top: 30px; width: 100%; max-width: 300px; padding: 18px; border-radius: 50px; background: var(--primary); color: white; border: none; font-size: 1.1rem; font-weight: 700; cursor: pointer; display: flex; justify-content: center; align-items: center; gap: 10px; transition: 0.2s; box-shadow: 0 4px 15px rgba(79, 70, 229, 0.4); }
        .btn-capture:active { transform: scale(0.95); }
        .btn-manual { margin-top: 15px; background: transparent; color: #9ca3af; border: 1px solid #4b5563; font-size: 0.9rem; padding: 12px 20px; border-radius: 30px; cursor: pointer; }
        
        /* 底部导航 */
        .bottom-nav { background: var(--surface); display: flex; padding: 10px 0; border-top: 1px solid #374151; padding-bottom: env(safe-area-inset-bottom); }
        .nav-item { flex: 1; display: flex; flex-direction: column; align-items: center; color: #9ca3af; text-decoration: none; font-size: 0.75rem; font-weight: 600; gap: 5px; opacity: 0.7; }
        .nav-item.active { color: var(--primary); opacity: 1; }
        .nav-item i { font-size: 1.3rem; }
    </style>
</head>
<body>

    <div class="header">
        <div class="logo">Smart<span>VMS</span></div>
        <div class="user-badge"><i class="fas fa-shield-alt"></i> Guard Post A</div>
    </div>

    <div class="main-content">
        <h2 style="font-size: 1.2rem; margin-bottom: 15px; text-align: center;">Plate Recognition</h2>
        
        <div class="mode-switch">
            <button class="mode-btn active" id="mode-entry" onclick="setMode('ENTRY')">IN (ENTRY)</button>
            <button class="mode-btn" id="mode-exit" onclick="setMode('EXIT')">OUT (EXIT)</button>
        </div>
        
        <div class="camera-container">
            <video id="videoElement" autoplay playsinline></video>
            <div class="scan-overlay">
                <div class="scan-line"></div>
                <div style="position:absolute; top:20px; left:20px; width:30px; height:30px; border-top:4px solid white; border-left:4px solid white;"></div>
                <div style="position:absolute; top:20px; right:20px; width:30px; height:30px; border-top:4px solid white; border-right:4px solid white;"></div>
                <div style="position:absolute; bottom:20px; left:20px; width:30px; height:30px; border-bottom:4px solid white; border-left:4px solid white;"></div>
                <div style="position:absolute; bottom:20px; right:20px; width:30px; height:30px; border-bottom:4px solid white; border-right:4px solid white;"></div>
            </div>
        </div>

        <button class="btn-capture" onclick="processScan()">
            <i class="fas fa-camera"></i> CAPTURE & VERIFY
        </button>
        
        <button class="btn-manual" onclick="manualEntry()">
            <i class="fas fa-keyboard"></i> Manual Entry Fallback
        </button>
    </div>

    <div class="bottom-nav">
        <a href="#" class="nav-item active"><i class="fas fa-qrcode"></i><span>Scan</span></a>
        <a href="#" class="nav-item"><i class="fas fa-list"></i><span>Live Logs</span></a>
        <a href="../core/logout.php" class="nav-item"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a> 
    </div>

    <div class="bottom-nav">
        <a href="guard_scan.php" class="nav-item active"><i class="fas fa-qrcode"></i><span>Scan</span></a>
        <a href="guard_logs.php" class="nav-item"><i class="fas fa-list"></i><span>Live Logs</span></a>
        <a href="../core/logout.php" class="nav-item"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a> 
    </div>

    <script>
        // 0. 模式控制逻辑
        let currentMode = 'ENTRY'; // 默认进门模式

        function setMode(mode) {
            currentMode = mode;
            document.getElementById('mode-entry').classList.toggle('active', mode === 'ENTRY');
            document.getElementById('mode-exit').classList.toggle('active', mode === 'EXIT');
        }

        // 1. 调用真实摄像头 (加入自动回退机制)
        const video = document.getElementById('videoElement');
        if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
            navigator.mediaDevices.getUserMedia({ video: { facingMode: "environment" } })
                .then(function (stream) { video.srcObject = stream; })
                .catch(function (err) { 
                    console.log("找不到后置镜头，尝试调用普通/前置镜头...", err);
                    navigator.mediaDevices.getUserMedia({ video: true })
                        .then(function (stream) { video.srcObject = stream; })
                        .catch(function (err2) { console.error("摄像头彻底调用失败: ", err2); });
                });
        }

        // 2. 核心演示逻辑：Capture -> OCR Confidence -> Result
        function processScan() {
            Swal.fire({
                title: 'Recognizing Plate...',
                text: 'AI OCR Engine processing image',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            setTimeout(() => {
                let confidence = (Math.random() * (0.99 - 0.60) + 0.60).toFixed(2);
                let recognizedPlate = 'VAA8899'; // 模拟识别到的车牌

                if (confidence < 0.75) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Low Confidence: ' + (confidence*100) + '%',
                        text: 'OCR engine is unsure. Please verify manually.',
                        input: 'text',
                        inputValue: recognizedPlate,
                        inputAttributes: { autocapitalize: 'characters' },
                        showCancelButton: true,
                        confirmButtonText: 'Confirm & Check',
                        confirmButtonColor: '#4f46e5'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            checkGateAccess(result.value);
                        }
                    });
                } else {
                    checkGateAccess(recognizedPlate);
                }
            }, 1500);
        }

        // 3. 门禁决策 (真实调用后端 API 查询数据库)
        function checkGateAccess(plateNo) {
            Swal.fire({ title: `Processing ${currentMode}...`, didOpen: () => { Swal.showLoading(); } });
            
            let formData = new FormData();
            formData.append('plate_no', plateNo);
            formData.append('gate_action', currentMode); // 告诉后端是进还是出
            
            fetch('../api/gate_check.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (data.action === 'ENTRY') {
                        // 进门成功的 UI
                        Swal.fire({
                            icon: 'success',
                            title: '✅ ENTRY GRANTED',
                            html: `
                                <div style="text-align:left; background:#1f2937; padding:15px; border-radius:8px; margin-top:10px; border:1px solid #374151;">
                                    <p style="color:#9ca3af; font-size:0.9rem;">Plate Number</p>
                                    <p style="font-size:1.5rem; font-family:monospace; font-weight:bold; color:#10b981;">${data.plate_no}</p>
                                    <hr style="border-color:#374151; margin:10px 0;">
                                    <p style="color:#9ca3af; font-size:0.9rem;">Visitor Name: <span style="color:white; font-weight:bold;">${data.visitor_name}</span></p>
                                    <p style="color:#9ca3af; font-size:0.9rem;">Allocated Parking: <span style="background:#4f46e5; color:white; padding:2px 8px; border-radius:4px; font-weight:bold;">${data.parking_slot}</span></p>
                                </div>
                            `,
                            confirmButtonText: 'Gate Opened',
                            confirmButtonColor: '#10b981'
                        });
                    } else {
                        // 出门成功的 UI
                        Swal.fire({
                            icon: 'success', 
                            title: '👋 EXIT GRANTED',
                            html: `
                                <div style="text-align:left; background:#1f2937; padding:15px; border-radius:8px; margin-top:10px;">
                                    <p style="font-size:1.5rem; font-family:monospace; font-weight:bold; color:#4f46e5;">${data.plate_no}</p>
                                    <p style="color:#10b981; font-weight:bold; font-size:0.9rem; margin-top:10px;">${data.message}</p>
                                </div>
                            `,
                            confirmButtonText: 'Gate Opened', 
                            confirmButtonColor: '#4f46e5'
                        });
                    }
                } else {
                    // 拒绝进/出的 UI (防潜回、无预约等)
                    Swal.fire({
                        icon: 'error',
                        title: '❌ ACCESS DENIED',
                        text: data.reason, 
                        confirmButtonText: 'Close',
                        confirmButtonColor: '#ef4444'
                    });
                }
            })
            .catch(error => {
                Swal.fire('Error', 'Failed to connect to server.', 'error');
            });
        }

        // 4. 手动输入 Fallback
        function manualEntry() {
            Swal.fire({
                title: 'Manual Plate Entry',
                input: 'text',
                inputPlaceholder: 'e.g. WXX1234',
                showCancelButton: true,
                confirmButtonColor: '#4f46e5'
            }).then((result) => {
                if (result.isConfirmed && result.value) {
                    checkGateAccess(result.value);
                }
            });
        }
    </script>
</body>
</html>