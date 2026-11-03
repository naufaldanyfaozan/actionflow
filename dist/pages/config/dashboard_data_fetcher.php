<?php

/**
 * Runs the same set of queries used by every role's dashboard index.php
 * and returns the data shaped for buildDashboardExcel().
 * $filterBulan / $filterPlant must already be resolved by the caller,
 * since each role folder computes its own plant-filter default.
 */
function fetchDashboardData($koneksi, string $filterBulan, string $filterPlant): array
{
    $whereFPM = "WHERE DATE_FORMAT(tgl_terbit, '%Y-%m') = '$filterBulan'";
    $whereAsakai = "WHERE DATE_FORMAT(tanggal, '%Y-%m') = '$filterBulan'";

    if (!empty($filterPlant)) {
        $whereFPM .= " AND plant = '$filterPlant'";
        $whereAsakai .= " AND plant = '$filterPlant'";
    }

    // ================= FPM PER BULAN =================
    $queryFPM = mysqli_query($koneksi, "SELECT DATE_FORMAT(tgl_terbit, '%Y-%m') as bulan,
    jenis_fpm, COUNT(*) as total FROM fpm_header $whereFPM GROUP BY bulan, jenis_fpm ORDER BY bulan ASC");

    $fpmData = [];
    while ($row = mysqli_fetch_assoc($queryFPM)) {
        $fpmData[$row['bulan']][$row['jenis_fpm']] = $row['total'];
    }

    // ================= ASAKAI PER MACHINE =================
    $queryAsakaiMachine = mysqli_query($koneksi, "SELECT machine, COUNT(*) as total FROM asakai_header
    $whereAsakai GROUP BY machine ORDER BY total DESC");

    $machineTotal = [];
    while ($row = mysqli_fetch_assoc($queryAsakaiMachine)) {
        $machineTotal[] = $row['total'];
    }

    // ================= FPM DOWN TIME OPEN / CLOSED =================
    $queryFpmStatus = mysqli_query($koneksi, "SELECT
    SUM(
        CASE
            WHEN (
                EXISTS (SELECT 1 FROM fpm_corrective_action ca WHERE ca.id_fpm = h.id_fpm AND ca.status != 'CLOSED')
                OR
                EXISTS (SELECT 1 FROM fpm_preventive_action pa WHERE pa.id_fpm = h.id_fpm AND pa.status != 'CLOSED')
            )
            THEN 1 ELSE 0
        END
    ) as total_open,
    SUM(
        CASE
            WHEN (
                NOT EXISTS (SELECT 1 FROM fpm_corrective_action ca WHERE ca.id_fpm = h.id_fpm AND ca.status != 'CLOSED')
                AND
                NOT EXISTS (SELECT 1 FROM fpm_preventive_action pa WHERE pa.id_fpm = h.id_fpm AND pa.status != 'CLOSED')
            )
            THEN 1 ELSE 0
        END
    ) as total_closed
    FROM fpm_header h
    $whereFPM
    AND h.jenis_fpm = 'DOWN TIME'
    ");

    $fpmStatus = mysqli_fetch_assoc($queryFpmStatus);
    $totalOpen = $fpmStatus['total_open'] ?? 0;
    $totalClosed = $fpmStatus['total_closed'] ?? 0;

    // ================= FPM ACTION STATUS PER DEPT (Corrective + Preventive) =================
    $queryFpmActionDept = mysqli_query($koneksi, "SELECT pic AS dept,
        SUM(CASE WHEN status = 'OPEN' THEN 1 ELSE 0 END) AS total_open,
        SUM(CASE WHEN status = 'CLOSED' THEN 1 ELSE 0 END) AS total_closed
    FROM (
        SELECT ca.pic, ca.status, ca.due_date FROM fpm_corrective_action ca
        JOIN fpm_header h ON ca.id_fpm = h.id_fpm $whereFPM
        UNION ALL
        SELECT pa.pic, pa.status, pa.due_date FROM fpm_preventive_action pa
        JOIN fpm_header h ON pa.id_fpm = h.id_fpm $whereFPM
    ) AS gabungan
    GROUP BY pic
    ORDER BY pic ASC
    ");

    $fpmActionDept = [];
    while ($row = mysqli_fetch_assoc($queryFpmActionDept)) {
        $total = $row['total_open'] + $row['total_closed'];
        $percent = $total > 0 ? round(($row['total_closed'] / $total) * 100, 2) : 0;
        $fpmActionDept[] = [
            'dept' => $row['dept'],
            'open' => $row['total_open'],
            'closed' => $row['total_closed'],
            'percent' => $percent,
        ];
    }

    // ================= ASAKAI STATUS PER DEPT =================
    $queryAsakaiDept = mysqli_query($koneksi, "SELECT pic AS dept,
        SUM(CASE WHEN status = 'OPEN' THEN 1 ELSE 0 END) AS total_open,
        SUM(CASE WHEN status = 'CLOSED' THEN 1 ELSE 0 END) AS total_closed
    FROM (
        SELECT ng.pic, ng.status, ng.due_date FROM asakai_countermeasure_ng ng
        JOIN asakai_header ah ON ng.id_asakai = ah.id_asakai $whereAsakai
        UNION ALL
        SELECT dt.pic, dt.status, dt.due_date FROM asakai_countermeasure_downtime dt
        JOIN asakai_header ah ON dt.id_asakai = ah.id_asakai $whereAsakai
    ) AS gabungan
    GROUP BY pic
    ORDER BY pic ASC
    ");

    $asakaiDept = [];
    while ($row = mysqli_fetch_assoc($queryAsakaiDept)) {
        $total = $row['total_open'] + $row['total_closed'];
        $percent = $total > 0 ? round(($row['total_closed'] / $total) * 100, 2) : 0;
        $asakaiDept[] = [
            'dept' => $row['dept'],
            'open' => $row['total_open'],
            'closed' => $row['total_closed'],
            'percent' => $percent,
        ];
    }

    // ================= ANALISA JENIS MASALAH =================
    $queryJenisMasalah = mysqli_query($koneksi, "SELECT jenis_masalah, COUNT(*) AS total
    FROM fpm_header $whereFPM GROUP BY jenis_masalah ORDER BY total DESC LIMIT 10");

    $jenisMasalahLabel = [];
    $jenisMasalahTotal = [];
    while ($row = mysqli_fetch_assoc($queryJenisMasalah)) {
        $jenisMasalahLabel[] = $row['jenis_masalah'];
        $jenisMasalahTotal[] = $row['total'];
    }

    $queryPartMasalah = mysqli_query($koneksi, "SELECT CONCAT(nama_part,' - ',jenis_masalah) AS label, COUNT(*) AS total
    FROM fpm_header $whereFPM GROUP BY nama_part, jenis_masalah ORDER BY total DESC LIMIT 15");

    $partMasalahLabel = [];
    $partMasalahTotal = [];
    while ($row = mysqli_fetch_assoc($queryPartMasalah)) {
        $partMasalahLabel[] = $row['label'];
        $partMasalahTotal[] = $row['total'];
    }

    $queryMachineMasalah = mysqli_query($koneksi, "SELECT CONCAT(machine_no,' - ',jenis_masalah) AS label, COUNT(*) AS total
    FROM fpm_header $whereFPM GROUP BY machine_no, jenis_masalah ORDER BY total DESC LIMIT 15");

    $machineMasalahLabel = [];
    $machineMasalahTotal = [];
    while ($row = mysqli_fetch_assoc($queryMachineMasalah)) {
        $machineMasalahLabel[] = $row['label'];
        $machineMasalahTotal[] = $row['total'];
    }

    // ================= FPM OVERDUE COUNTERMEASURE =================
    $queryFpmOverdue = mysqli_query($koneksi, "SELECT pic, tindakan AS deskripsi, due_date, status, machine_no, nama_part
    FROM (
        SELECT ca.pic, ca.tindakan, ca.due_date, ca.status, ca.actual_date, h.machine_no, h.nama_part FROM fpm_corrective_action ca
        JOIN fpm_header h ON ca.id_fpm = h.id_fpm $whereFPM
        UNION ALL
        SELECT pa.pic, pa.tindakan, pa.due_date, pa.status, pa.actual_date, h.machine_no, h.nama_part FROM fpm_preventive_action pa
        JOIN fpm_header h ON pa.id_fpm = h.id_fpm $whereFPM
        UNION ALL
        SELECT rd.pic, rd.dokumen AS tindakan, rd.due_date, rd.status, rd.actual_date, h.machine_no, h.nama_part FROM fpm_review_dokumen rd
        JOIN fpm_header h ON rd.id_fpm = h.id_fpm $whereFPM
    ) AS gabungan
    WHERE status != 'CLOSED'
    AND (actual_date IS NULL OR actual_date = '')
    AND due_date < CURDATE()
    ORDER BY due_date ASC
    ");

    $fpmOverdue = [];
    while ($row = mysqli_fetch_assoc($queryFpmOverdue)) {
        $fpmOverdue[] = $row;
    }

    // ================= ASAKAI OVERDUE COUNTERMEASURE =================
    $queryAsakaiOverdue = mysqli_query($koneksi, "SELECT pic, deskripsi, due_date, status, machine, namapart
    FROM (
        SELECT ng.pic, ng.deskripsi, ng.due_date, ng.status, ng.actual_date, ah.machine, ah.namapart FROM asakai_countermeasure_ng ng
        JOIN asakai_header ah ON ng.id_asakai = ah.id_asakai $whereAsakai
        UNION ALL
        SELECT dt.pic, dt.deskripsi, dt.due_date, dt.status, dt.actual_date, ah.machine, ah.namapart FROM asakai_countermeasure_downtime dt
        JOIN asakai_header ah ON dt.id_asakai = ah.id_asakai $whereAsakai
    ) AS gabungan
    WHERE status != 'CLOSED'
    AND (actual_date IS NULL OR actual_date = '')
    AND due_date < CURDATE()
    ORDER BY due_date ASC
    ");

    $asakaiOverdue = [];
    while ($row = mysqli_fetch_assoc($queryAsakaiOverdue)) {
        $asakaiOverdue[] = $row;
    }

    // ================= DELIVERY & SISA STOCK PART =================
    $tanggalDelivery = date('Y-m-d');
    $queryDelivery = mysqli_query($koneksi, "SELECT
        fdm.id_monitor,
        fdm.nama_part,
        fdm.act_stock,
        fdm.delivery_per_hari,
        fdm.sisa_stock,
        fdm.news_delivery,
        fdmc.id_countermeasure,
        fdmc.countermeasure,
        fdmc.due_date,
        fdmc.pic
    FROM `fpm_delivery_monitor` fdm
    LEFT JOIN `fpm_delivery_monitor_countermeasure` fdmc ON fdm.id_monitor = fdmc.id_monitor
    WHERE DATE(fdm.tanggal) = '$tanggalDelivery'
    ORDER BY fdm.id_monitor ASC
    ");

    $delivery = [];
    while ($row = mysqli_fetch_assoc($queryDelivery)) {
        $delivery[] = $row;
    }

    return [
        'kpi' => [
            'down_time' => array_sum(array_map('array_sum', $fpmData)),
            'closed' => $totalClosed,
            'open' => $totalOpen,
            'asakai' => array_sum($machineTotal),
        ],
        'fpm_action_dept' => $fpmActionDept,
        'asakai_dept' => $asakaiDept,
        'fpm_overdue' => $fpmOverdue,
        'asakai_overdue' => $asakaiOverdue,
        'jenis_masalah' => ['label' => $jenisMasalahLabel, 'total' => $jenisMasalahTotal],
        'part_masalah' => ['label' => $partMasalahLabel, 'total' => $partMasalahTotal],
        'machine_masalah' => ['label' => $machineMasalahLabel, 'total' => $machineMasalahTotal],
        'delivery' => $delivery,
    ];
}
