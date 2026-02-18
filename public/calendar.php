<?php
// public/calendar.php
session_start();
require_once '../app/config/database.php';
require_once '../app/functions/security.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Finansal Takvim</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js'></script>
    
    <style>
        .fc-event { cursor: pointer; font-size: 0.85rem; transition: 0.2s; }
        .fc-event:hover { transform: scale(1.02); }
        .fc-day-today { background-color: rgba(255, 193, 7, 0.1) !important; }
        #calendar { max-width: 100%; margin: 0 auto; min-height: 800px; background: white; padding: 20px; border-radius: 10px; }
        .fc-toolbar-title { font-size: 1.5rem !important; color: #333; }
        .fc-button { font-size: 0.9rem !important; }
        
        /* Modal yükleniyor animasyonu */
        .modal-loading { display: flex; justify-content: center; align-items: center; height: 200px; }
    </style>
</head>
<body class="bg-light">
    <?php include 'includes/navbar.php'; ?>

    <div class="container-fluid px-4 py-4">
        
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h2 class="mb-0 text-dark"><i class="fa fa-calendar-alt text-primary"></i> Finansal Takvim</h2>
                <p class="text-muted small">Ödeme ve tahsilat planınızı buradan takip edebilirsiniz.</p>
            </div>
            <div>
                <a href="transaction-add.php" class="btn btn-success shadow-sm"><i class="fa fa-plus"></i> Yeni İşlem Ekle</a>
            </div>
        </div>

        <div class="card shadow border-0">
            <div class="card-body p-0">
                <div id="calendar"></div>
            </div>
        </div>

    </div>

    <div class="modal fade" id="eventModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold" id="modalTitle">İşlem Detayı</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2">
                        <label class="fw-bold text-muted small">Cari / Firma</label>
                        <div class="fs-5" id="modalCompany"></div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-6">
                            <label class="fw-bold text-muted small">Tarih</label>
                            <div id="modalDate"></div>
                        </div>
                        <div class="col-6">
                            <label class="fw-bold text-muted small">Tutar</label>
                            <div class="fs-5 fw-bold" id="modalAmount"></div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="fw-bold text-muted small">Açıklama</label>
                        <div class="p-2 bg-light rounded border" id="modalDesc"></div>
                    </div>
                    <div class="alert alert-secondary py-2 small" id="modalType"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                    <button type="button" id="btnEditPopup" class="btn btn-primary"><i class="fa fa-edit"></i> Düzenle</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">İşlem Düzenle</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="editModalBody">
                    </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var calendarEl = document.getElementById('calendar');
            var eventModal = new bootstrap.Modal(document.getElementById('eventModal'));
            
            var calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                locale: 'tr', // Türkçe
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,listMonth'
                },
                buttonText: { today: 'Bugün', month: 'Ay', week: 'Hafta', list: 'Liste' },
                themeSystem: 'bootstrap5',
                events: 'api-calendar-events.php', // Veri kaynağı
                eventTimeFormat: { hour: '2-digit', minute: '2-digit', meridiem: false },
                dayMaxEvents: true,
                selectable: true, 
                
                // --- Olay Tıklanınca (Özet Modalı Aç) ---
                eventClick: function(info) {
                    var props = info.event.extendedProps;
                    
                    // Özet Modalını Doldur
                    document.getElementById('modalTitle').innerText = props.type_label;
                    document.getElementById('modalCompany').innerText = props.full_title;
                    
                    var amtEl = document.getElementById('modalAmount');
                    amtEl.innerText = props.amount;
                    if(info.event.backgroundColor === '#198754') { // Gelir
                        amtEl.className = 'fs-5 fw-bold text-success';
                    } else { // Gider
                        amtEl.className = 'fs-5 fw-bold text-danger';
                    }

                    document.getElementById('modalDate').innerText = info.event.start.toLocaleDateString('tr-TR');
                    document.getElementById('modalDesc').innerText = props.description || '-';
                    document.getElementById('modalType').innerText = 'Kayıt ID: #' + info.event.id;
                    
                    // "Düzenle" butonuna tıklama olayı ekle (Öncekini temizle)
                    var editBtn = document.getElementById('btnEditPopup');
                    editBtn.onclick = function() {
                        eventModal.hide(); // Özet modalını kapat
                        openEditModal(info.event.id); // Düzenleme modalını aç
                    };
                    
                    eventModal.show();
                },

                // --- Boş Tarihe Tıklayınca (Yeni Kayıt) ---
                dateClick: function(info) {
                    // Seçilen tarihle yeni ekleme sayfasına git
                    window.location.href = 'transaction-add.php?date=' + info.dateStr;
                }
            });

            calendar.render();
        });

        // --- Düzenleme Modalını Açan Fonksiyon ---
        // Bu fonksiyon transaction-edit.php'yi modal içine yükler
        function openEditModal(id) {
            var modal = new bootstrap.Modal(document.getElementById('editModal'));
            modal.show();
            
            // Yükleniyor ikonu göster
            $('#editModalBody').html('<div class="modal-loading"><div class="spinner-border text-primary"></div></div>');
            
            // İçeriği çek ve bas
            $('#editModalBody').load('transaction-edit.php?id=' + id, function(response, status, xhr) {
                if (status == "error") {
                    $('#editModalBody').html('<div class="alert alert-danger">Hata oluştu: ' + xhr.status + ' ' + xhr.statusText + '</div>');
                }
            });
        }
        
        // transaction-edit.php içindeki kaydetme işleminden sonra takvimi yenilemek için
        // (Opsiyonel: Eğer transaction-edit.php success olduğunda window.location.reload() yapıyorsa buna gerek yok)
        // Ama AJAX ile yapıyorsak calendar.refetchEvents() kullanabiliriz.
    </script>
</body>
</html>