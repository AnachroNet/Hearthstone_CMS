<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'admin_table_alignment.php';

/**
 * Shared admin panel layout/styles (User Management, Settings, CRUD pages).
 */
function sahtout_admin_page_styles(): void
{
    ?>
<style>
        * { font-family: 'Inter', sans-serif; }

        body {
            min-height: 100vh;
            color: #d8d8d8;
            background: #05070b;
            background-image:
                radial-gradient(1000px 700px at -10% 35%, rgba(59,130,246,.14), transparent 65%),
                radial-gradient(800px 600px at -5% 85%, rgba(124,58,237,.10), transparent 70%),
                linear-gradient(180deg, #0a0e16 0%, #060810 45%, #03040a 100%);
            background-attachment: fixed;
        }

        .panel {
            position: relative;
            background: linear-gradient(180deg, rgba(22,25,32,.92), rgba(8,10,14,.9));
            border: 1px solid rgba(201,162,39,.22);
            box-shadow: 0 12px 32px rgba(0,0,0,.55), inset 0 0 60px rgba(0,0,0,.45);
        }

        .panel::before {
            content: '';
            position: absolute;
            inset: 5px;
            border: 1px solid rgba(201,162,39,.14);
            pointer-events: none;
        }

        .panel::after {
            content: '';
            position: absolute;
            inset: 0;
            pointer-events: none;
            background:
                linear-gradient(#e8c552,#e8c552) left top / 18px 2px,
                linear-gradient(#e8c552,#e8c552) left top / 2px 18px,
                linear-gradient(#e8c552,#e8c552) right top / 18px 2px,
                linear-gradient(#e8c552,#e8c552) right top / 2px 18px,
                linear-gradient(#e8c552,#e8c552) left bottom / 18px 2px,
                linear-gradient(#e8c552,#e8c552) left bottom / 2px 18px,
                linear-gradient(#e8c552,#e8c552) right bottom / 18px 2px,
                linear-gradient(#e8c552,#e8c552) right bottom / 2px 18px;
            background-repeat: no-repeat;
        }

        .wow-title {
            font-family: 'Cinzel', serif;
            font-weight: 900;
            background: linear-gradient(180deg, #fff7d6 0%, #f2cf5b 35%, #c9a227 62%, #8a6a14 100%);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
            filter: drop-shadow(0 3px 6px rgba(0,0,0,.85));
        }

        .section-title {
            font-family: 'Cinzel', serif;
            font-weight: 700;
            color: #f2cf5b;
            text-shadow: 0 0 12px rgba(201,162,39,.35), 0 2px 4px rgba(0,0,0,.8);
        }

        .form-label {
            display: block;
            font-family: 'Cinzel', serif;
            font-weight: 700;
            color: #f2cf5b;
            font-size: 0.875rem;
            margin-bottom: 0.35rem;
        }

        .btn-gold {
            display: inline-flex;
            align-items: center;
            gap: .55rem;
            padding: .75rem 1.5rem;
            font-weight: 800;
            font-size: .85rem;
            letter-spacing: .04em;
            text-transform: uppercase;
            clip-path: polygon(10px 0, 100% 0, 100% calc(100% - 10px), calc(100% - 10px) 100%, 0 100%, 0 10px);
            transition: transform .2s ease;
            background: linear-gradient(180deg, #f6d478 0%, #c9a227 48%, #8a6a14 100%);
            color: #1a1200;
            text-shadow: 0 1px 0 rgba(255,255,255,.35);
            box-shadow: inset 0 0 0 1px rgba(255,255,255,.28), inset 0 -8px 14px rgba(0,0,0,.25);
            border: none;
            cursor: pointer;
            text-decoration: none;
        }
        .btn-gold:hover { transform: translateY(-2px) scale(1.02); }

        .btn-iron {
            display: inline-flex;
            align-items: center;
            gap: .55rem;
            padding: .75rem 1.5rem;
            font-weight: 800;
            font-size: .85rem;
            letter-spacing: .04em;
            text-transform: uppercase;
            clip-path: polygon(10px 0, 100% 0, 100% calc(100% - 10px), calc(100% - 10px) 100%, 0 100%, 0 10px);
            transition: transform .2s ease;
            background: linear-gradient(180deg, #3a404d 0%, #232833 55%, #141821 100%);
            color: #cfe1ff;
            box-shadow: inset 0 0 0 1px rgba(120,160,255,.25), inset 0 -8px 14px rgba(0,0,0,.4);
            border: none;
            cursor: pointer;
            text-decoration: none;
        }
        .btn-iron:hover { transform: translateY(-2px) scale(1.02); }
        .btn-iron-sm { padding: .4rem 1rem; font-size: .75rem; }

        .btn-danger {
            display: inline-flex;
            align-items: center;
            gap: .55rem;
            padding: .75rem 1.5rem;
            font-weight: 800;
            font-size: .85rem;
            letter-spacing: .04em;
            text-transform: uppercase;
            clip-path: polygon(10px 0, 100% 0, 100% calc(100% - 10px), calc(100% - 10px) 100%, 0 100%, 0 10px);
            transition: transform .2s ease;
            background: linear-gradient(180deg, #ff4d4d 0%, #cc0000 48%, #8a0000 100%);
            color: #fff;
            box-shadow: inset 0 0 0 1px rgba(255,255,255,.2), inset 0 -8px 14px rgba(0,0,0,.3);
            border: none;
            cursor: pointer;
        }
        .btn-danger:hover { transform: translateY(-2px) scale(1.02); }
        .btn-danger-sm { padding: .4rem 1rem; font-size: .75rem; }

        .input-dark {
            background: rgba(10, 14, 22, 0.8);
            border: 1px solid rgba(201,162,39,.3);
            color: #e5e7eb;
            padding: 0.75rem 1rem;
            font-size: 0.95rem;
            transition: all 0.2s ease;
            outline: none;
            width: 100%;
        }
        .input-dark:focus {
            border-color: #f2cf5b;
            box-shadow: 0 0 10px rgba(242,207,82,.2);
            background: rgba(15, 20, 30, 0.9);
        }
        .input-dark::placeholder { color: rgba(150, 170, 200, 0.4); }
        .input-dark option { background: #0a0e16; }
        .input-dark:disabled { opacity: 0.5; cursor: not-allowed; }

        .textarea-dark {
            min-height: 6rem;
            resize: vertical;
        }

        .table-dark {
            width: 100%;
            border-collapse: collapse;
        }
        .table-dark th {
            background: rgba(10, 14, 22, 0.9);
            color: #f2cf5b;
            font-family: 'Cinzel', serif;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
            padding: 1rem;
            border-bottom: 2px solid rgba(201,162,39,.4);
            text-align: left;
        }
        .table-dark td {
            padding: 1rem;
            border-bottom: 1px solid rgba(201,162,39,.1);
            color: #d8d8d8;
            background: rgba(22, 25, 32, 0.6);
            vertical-align: middle;
        }
        .table-dark tr:hover td {
            background: rgba(30, 35, 45, 0.8);
        }

        <?php sahtout_admin_table_alignment_css(); ?>

        .badge-active { color: #4ade80; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.05em; }
        .badge-inactive { color: #f87171; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.05em; }
        .badge-on { color: #86efac; font-size: .75rem; }
        .badge-off { color: #9ca3af; font-size: .75rem; }

        .main-content-area {
            transition: margin-left 0.3s ease;
            min-height: calc(100vh - 72px);
            width: 100%;
        }

        .content-wrapper {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 1rem;
        }

        @media (min-width: 640px) {
            .content-wrapper { padding: 0 1.5rem; }
        }

        @media (min-width: 1024px) {
            .content-wrapper { padding: 0 2rem; }
            .main-content-area.lg\:ml-\[280px\] { margin-left: 280px; }
        }

        @media (min-width: 1280px) {
            .content-wrapper { padding: 0 2.5rem; }
        }

        @media (max-width: 1023px) {
            .main-content-area {
                margin-left: 0 !important;
                padding: 1rem;
            }
            .content-wrapper { padding: 0 0.5rem; }
        }

        .modal-backdrop {
            position: fixed;
            inset: 0;
            z-index: 99999;
            background: rgba(0, 0, 0, 0.85);
            backdrop-filter: blur(8px);
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        .modal-backdrop .panel {
            max-height: 90vh;
            overflow-y: auto;
            width: 100%;
        }

        .tab-link {
            color: #b8c8ff;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            text-decoration: none;
            border-bottom: 2px solid transparent;
            transition: all 0.3s ease;
            display: inline-block;
        }
        .tab-link:hover { color: #f2cf5b; }
        .tab-link.active { color: #f2cf5b; border-bottom-color: #f2cf5b; }

        .admin-subtab { display: none; }
        .admin-subtab.active { display: block; }
        .sub-tab-link { padding: 0.5rem 1rem; font-size: 0.9rem; }

        .admin-form-actions {
            display: flex;
            flex-direction: row;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.75rem;
            padding-top: 0.5rem;
        }

        .event-recurrence-days {
            display: flex;
            flex-direction: row;
            flex-wrap: wrap;
            gap: 0.75rem;
            align-items: center;
        }
        .event-recurrence-days > label {
            display: inline-flex;
            width: auto;
            margin: 0;
        }
</style>
    <?php
}
