<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

/** Shared admin table column alignment (use with .table-dark and .wow-table). */
function sahtout_admin_table_alignment_css(): void
{
    ?>
        .table-dark th.align-right,
        .table-dark td.align-right,
        .wow-table th.align-right,
        .wow-table td.align-right {
            text-align: right;
        }
        .table-dark th.align-center,
        .table-dark td.align-center,
        .wow-table th.align-center,
        .wow-table td.align-center {
            text-align: center;
        }
        .table-dark th.col-num,
        .table-dark td.col-num,
        .wow-table th.col-num,
        .wow-table td.col-num {
            text-align: right;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }
        .table-dark th.col-actions,
        .table-dark td.actions-cell,
        .wow-table th.col-actions,
        .wow-table td.actions-cell {
            text-align: right;
            vertical-align: middle;
            min-width: 11.5rem;
        }
        .table-dark .action-buttons,
        .wow-table .action-buttons {
            display: inline-flex;
            flex-direction: row;
            flex-wrap: nowrap;
            gap: 0.5rem;
            justify-content: flex-end;
            align-items: center;
        }
        .table-dark .action-buttons--stack,
        .wow-table .action-buttons--stack {
            flex-direction: column;
            align-items: flex-end;
            flex-wrap: nowrap;
        }
        .table-dark td.actions-cell,
        .wow-table td.actions-cell {
            white-space: nowrap;
        }
        .table-dark td.col-schedule,
        .wow-table td.col-schedule {
            max-width: 16rem;
            white-space: normal;
            line-height: 1.35;
        }
    <?php
}
