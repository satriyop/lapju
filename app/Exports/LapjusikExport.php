<?php

namespace App\Exports;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskProgress;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class LapjusikExport implements FromArray, ShouldAutoSize, WithColumnWidths, WithEvents, WithStyles, WithTitle
{
    protected Project $project;

    protected int $month;

    protected int $year;

    protected array $calendarDays = [];

    protected array $tasksWithProgress = [];

    protected int $dataStartRow = 10;

    public function __construct(Project $project, int $month, int $year)
    {
        $this->project = $project;
        $this->month = $month;
        $this->year = $year;
        $this->calendarDays = $this->getCalendarDays();
        $this->tasksWithProgress = $this->getTasksWithProgress();
    }

    public function title(): string
    {
        $monthName = Carbon::create($this->year, $this->month, 1)->locale('id')->translatedFormat('F');

        return 'Lapjusik '.$monthName.' '.$this->year;
    }

    public function array(): array
    {
        $monthName = Carbon::create($this->year, $this->month, 1)->locale('id')->translatedFormat('F');
        $rows = [];

        // Row 1: Title
        $rows[] = $this->padRow(['LAPJUSIK HARIAN']);

        // Row 2: Subtitle
        $rows[] = $this->padRow(['LAPORAN KEMAJUAN FISIK HARIAN']);

        // Row 3: Empty
        $rows[] = $this->padRow([]);

        // Row 4: Project name
        $rows[] = $this->padRow(['', 'PEKERJAAN', ': '.$this->project->name]);

        // Row 5: Location
        $rows[] = $this->padRow(['', 'LOKASI', ': '.($this->project->location?->province_name ?? '-')]);

        // Row 6: Fiscal year
        $rows[] = $this->padRow(['', 'TAHUN ANGGARAN', ': '.$this->year]);

        // Row 7: Empty
        $rows[] = $this->padRow([]);

        // Row 8: Table header row 1 (Month name spanning day columns)
        $headerRow1 = ['NO', 'URAIAN PEKERJAAN'];
        foreach ($this->calendarDays as $day) {
            $headerRow1[] = strtoupper($monthName);
        }
        $rows[] = $headerRow1;

        // Row 9: Table header row 2 (Day names and numbers)
        $headerRow2 = ['', ''];
        foreach ($this->calendarDays as $day) {
            $headerRow2[] = $day['dayName']."\n".$day['day'];
        }
        $rows[] = $headerRow2;

        // Data rows
        foreach ($this->tasksWithProgress as $task) {
            $row = [];

            // NO column - only for leaf tasks
            $row[] = $task['hasChildren'] ? '' : $task['numbering'];

            // URAIAN PEKERJAAN column
            $indent = str_repeat('  ', $task['depth']);
            if ($task['hasChildren']) {
                $row[] = $indent.$task['name'];
            } else {
                $row[] = $indent.'- '.$task['name'];
            }

            // Daily progress columns
            foreach ($this->calendarDays as $day) {
                $progress = $task['dailyProgress'][$day['date']] ?? null;
                if ($progress !== null && ! $task['hasChildren']) {
                    $row[] = number_format($progress, 2);
                } else {
                    $row[] = '';
                }
            }

            $rows[] = $row;
        }

        return $rows;
    }

    public function columnWidths(): array
    {
        $widths = [
            'A' => 6,  // NO
            'B' => 45, // URAIAN PEKERJAAN
        ];

        // Day columns - start from C
        $col = 'C';
        foreach ($this->calendarDays as $day) {
            $widths[$col] = 8;
            $col++;
        }

        return $widths;
    }

    public function styles(Worksheet $sheet): array
    {
        $lastCol = $this->getLastColumn();
        $lastRow = $this->dataStartRow + count($this->tasksWithProgress) - 1;

        return [
            // Title style
            1 => [
                'font' => ['bold' => true, 'size' => 14],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ],
            // Subtitle style
            2 => [
                'font' => ['bold' => true, 'size' => 10],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ],
            // Project info rows
            '4:6' => [
                'font' => ['bold' => false, 'size' => 10],
            ],
            // Header row 1
            8 => [
                'font' => ['bold' => true, 'size' => 9, 'color' => ['argb' => 'FFFFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FF047857'], // emerald-700
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ],
            // Header row 2
            9 => [
                'font' => ['bold' => true, 'size' => 8],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FFD1FAE5'], // emerald-100
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                    'wrapText' => true,
                ],
            ],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastCol = $this->getLastColumn();
                $lastRow = $this->dataStartRow + count($this->tasksWithProgress) - 1;

                // Merge title cells
                $sheet->mergeCells("A1:{$lastCol}1");
                $sheet->mergeCells("A2:{$lastCol}2");

                // Merge month header cells (row 8, columns C onwards)
                if (count($this->calendarDays) > 1) {
                    $sheet->mergeCells("C8:{$lastCol}8");
                }

                // Set row height for header row 2
                $sheet->getRowDimension(9)->setRowHeight(35);

                // Add borders to data table
                $tableRange = "A8:{$lastCol}{$lastRow}";
                $sheet->getStyle($tableRange)->applyFromArray([
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color' => ['argb' => 'FF9CA3AF'],
                        ],
                    ],
                ]);

                // Style parent rows (bold, light green background)
                $currentRow = $this->dataStartRow;
                foreach ($this->tasksWithProgress as $task) {
                    if ($task['hasChildren']) {
                        $sheet->getStyle("A{$currentRow}:{$lastCol}{$currentRow}")->applyFromArray([
                            'font' => ['bold' => true],
                            'fill' => [
                                'fillType' => Fill::FILL_SOLID,
                                'startColor' => ['argb' => 'FFECFDF5'], // emerald-50
                            ],
                        ]);
                    }
                    $currentRow++;
                }

                // Color weekend columns
                $colIndex = 2; // Start from column C (index 2)
                foreach ($this->calendarDays as $day) {
                    $col = $this->getColumnLetter($colIndex);
                    if ($day['isSunday']) {
                        // Red background for Sunday
                        $sheet->getStyle("{$col}9:{$col}{$lastRow}")->applyFromArray([
                            'fill' => [
                                'fillType' => Fill::FILL_SOLID,
                                'startColor' => ['argb' => 'FFFEE2E2'], // red-100
                            ],
                        ]);
                    } elseif ($day['isWeekend']) {
                        // Amber background for Saturday
                        $sheet->getStyle("{$col}9:{$col}{$lastRow}")->applyFromArray([
                            'fill' => [
                                'fillType' => Fill::FILL_SOLID,
                                'startColor' => ['argb' => 'FFFFFBEB'], // amber-50
                            ],
                        ]);
                    }
                    $colIndex++;
                }

                // Center align day columns
                $sheet->getStyle("C{$this->dataStartRow}:{$lastCol}{$lastRow}")->applyFromArray([
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                    ],
                ]);

                // Freeze panes (freeze columns A, B and rows 1-9)
                $sheet->freezePane('C10');
            },
        ];
    }

    private function getCalendarDays(): array
    {
        $startOfMonth = Carbon::create($this->year, $this->month, 1);
        $endOfMonth = $startOfMonth->copy()->endOfMonth();

        $days = [];
        $period = CarbonPeriod::create($startOfMonth, $endOfMonth);

        foreach ($period as $date) {
            $days[] = [
                'date' => $date->format('Y-m-d'),
                'day' => $date->day,
                'dayName' => strtoupper(substr($date->locale('id')->dayName, 0, 3)),
                'isWeekend' => $date->isWeekend(),
                'isSunday' => $date->isSunday(),
            ];
        }

        return $days;
    }

    private function getTasksWithProgress(): array
    {
        $tasks = Task::where('project_id', $this->project->id)
            ->orderBy('_lft')
            ->get();

        $startOfMonth = Carbon::create($this->year, $this->month, 1)->startOfDay();
        $endOfMonth = $startOfMonth->copy()->endOfMonth()->endOfDay();

        $progressRecords = TaskProgress::where('project_id', $this->project->id)
            ->whereBetween('progress_date', [$startOfMonth, $endOfMonth])
            ->get()
            ->groupBy(function ($progress) {
                $date = $progress->progress_date;
                if (is_string($date)) {
                    return Carbon::parse($date)->format('Y-m-d');
                }

                return $date->format('Y-m-d');
            })
            ->map(function ($dateGroup) {
                return $dateGroup->keyBy('task_id');
            });

        $result = [];
        $leafCounters = [];

        foreach ($tasks as $task) {
            $depth = 0;
            $currentId = $task->parent_id;

            while ($currentId !== null) {
                $depth++;
                $parent = $tasks->firstWhere('id', $currentId);
                $currentId = $parent?->parent_id;
            }

            $hasChildren = $task->children()->exists();

            $numbering = '';
            if (! $hasChildren && $task->parent_id !== null) {
                if (! isset($leafCounters[$task->parent_id])) {
                    $leafCounters[$task->parent_id] = 0;
                }
                $leafCounters[$task->parent_id]++;
                $numbering = (string) $leafCounters[$task->parent_id];
            }

            $dailyProgress = [];
            foreach ($this->calendarDays as $day) {
                $dateKey = $day['date'];
                $progress = null;

                if (isset($progressRecords[$dateKey]) && isset($progressRecords[$dateKey][$task->id])) {
                    $progress = $progressRecords[$dateKey][$task->id]->percentage;
                }

                $dailyProgress[$dateKey] = $progress;
            }

            $result[] = [
                'id' => $task->id,
                'name' => $task->name,
                'numbering' => $numbering,
                'depth' => $depth,
                'hasChildren' => $hasChildren,
                'dailyProgress' => $dailyProgress,
            ];
        }

        return $result;
    }

    private function getLastColumn(): string
    {
        // Column A = NO, Column B = URAIAN PEKERJAAN, then day columns
        $totalColumns = 2 + count($this->calendarDays);

        return $this->getColumnLetter($totalColumns - 1);
    }

    private function getColumnLetter(int $index): string
    {
        $letter = '';
        while ($index >= 0) {
            $letter = chr(65 + ($index % 26)).$letter;
            $index = intval($index / 26) - 1;
        }

        return $letter;
    }

    /**
     * Pad row to match total columns
     */
    private function padRow(array $row): array
    {
        $totalColumns = 2 + count($this->calendarDays);

        return array_pad($row, $totalColumns, '');
    }
}
