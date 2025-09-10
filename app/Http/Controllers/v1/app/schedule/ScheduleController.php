<?php

namespace App\Http\Controllers\v1\app\schedule;

use Carbon\Carbon;
use App\Models\Document;
use App\Models\Schedule;
use App\Models\ScheduleItem;
use Illuminate\Http\Request;
use App\Models\ProjectStatus;
use App\Traits\UtilHelperTrait;
use App\Models\ScheduleDocument;
use App\Enums\Types\NotifierEnum;
use App\Enums\Statuses\StatusEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\App;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Lang;
use App\Enums\Statuses\ScheduleStatusEnum;
use App\Http\Requests\v1\schedule\ScheduleRequest;
use App\Repositories\Storage\StorageRepositoryInterface;
use App\Http\Requests\v1\schedule\PresentScheduleRequest;
use App\Repositories\PendingTask\PendingTaskRepositoryInterface;
use App\Repositories\Notification\NotificationRepositoryInterface;
use App\Traits\FileHelperTrait;
use App\Traits\PathHelperTrait;

class ScheduleController extends Controller
{
    use UtilHelperTrait, PathHelperTrait, FileHelperTrait;
    protected $pendingTaskRepository;
    protected $storageRepository;
    protected $notificationRepository;

    public function __construct(
        PendingTaskRepositoryInterface $pendingTaskRepository,
        StorageRepositoryInterface $storageRepository,
        NotificationRepositoryInterface $notificationRepository,

    ) {
        $this->pendingTaskRepository = $pendingTaskRepository;
        $this->notificationRepository = $notificationRepository;
        $this->storageRepository = $storageRepository;
    }

    public function schedules(Request $request)
    {
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $locale = App::getLocale();
        $request = DB::table('schedules as sch')
            ->where('sch.date', '>=', $startDate)
            ->where('sch.date', '<=', $endDate)
            ->join('schedule_status_trans as scht', function ($join) use ($locale) {
                $join->on('scht.schedule_status_id', 'sch.schedule_status_id')
                    ->where('scht.language_name', $locale);
            })
            ->select('sch.id', 'scht.value as status', 'sch.date')->get();
        return response()->json(
            $request
        );
    }
    public function prepareSchedule(Request $request)
    {
        $count = $request->count ?? 10;
        $ids = $request->input('ids');

        if (is_string($ids)) {
            $decoded = json_decode($ids, true);
            $ids = is_array($decoded) ? $decoded : [];
        }

        $locale = App::getLocale();

        // 1. Always fetch selected (special) projects by ID — regardless of their current status
        $projectsFromIds = collect();
        if (!empty($ids)) {
            $projectsFromIds = DB::table('projects as pro')
                ->join('project_trans as prot', function ($join) use ($locale) {
                    $join->on('prot.project_id', '=', 'pro.id')
                        ->where('prot.language_name', $locale);
                })
                ->whereIn('pro.id', $ids)
                ->select('pro.id', 'prot.name')
                ->get();
        }

        $fetchedCount = $projectsFromIds->count();
        $remainingCount = $count - $fetchedCount;

        $remainingProjects = collect();

        // 2. Fetch remaining from pending projects (excluding already included ones)
        if ($remainingCount > 0) {
            $remainingProjects = DB::table('projects as pro')
                ->join('project_statuses as pros', function ($join) {
                    $join->on('pro.id', '=', 'pros.project_id')
                        ->where('is_active', true);
                })
                ->join('project_trans as prot', function ($join) use ($locale) {
                    $join->on('prot.project_id', '=', 'pro.id')
                        ->where('prot.language_name', $locale);
                })
                ->where('pros.status_id', StatusEnum::pending_for_schedule->value)
                ->whereNotIn('pro.id', $projectsFromIds->pluck('id'))
                ->select('pro.id', 'prot.name')
                ->limit($remainingCount)
                ->get();
        }

        // 3. Merge both groups and return
        $projects = $projectsFromIds->merge($remainingProjects)->take($count);

        return response()->json($projects);
    }

    public function submitSchedule(PresentScheduleRequest $request)
    {
        $request->validated();
        $authUser = $request->user();
        $schedule = Schedule::find($request->id);

        if (!$schedule) {
            return response()->json(['message' => __('app_translation.schedule_not_found')], 404);
        }

        DB::beginTransaction();

        $schedule->schedule_status_id = ScheduleStatusEnum::Completed->value;
        $schedule->save();

        $submittedItemIds = collect($request->input('schedule_items'))->pluck('id')->toArray();
        // Get all schedule item IDs from DB for the schedule
        $dbItemIds = DB::table('schedule_items')
            ->where('schedule_id', $schedule->id)
            ->pluck('id')
            ->toArray();

        // Check if submitted items match DB items exactly
        $allItemsPresent = empty(array_diff($dbItemIds, $submittedItemIds))
            && empty(array_diff($submittedItemIds, $dbItemIds));

        if (!$allItemsPresent) {
            // Handle error - some items are missing or extra items submitted
            return response()->json(['message' => __('app_translation.sched_item_tampered')], 400);
        }

        $statusTrans = DB::table('status_trans as st')
            ->whereIn('st.status_id', [
                StatusEnum::has_comment->value,
                StatusEnum::approved->value,
                StatusEnum::missed->value
            ])
            ->select(
                'st.status_id',
                DB::raw("MAX(CASE WHEN st.language_name = 'fa' THEN st.name END) as farsi"),
                DB::raw("MAX(CASE WHEN st.language_name = 'en' THEN st.name END) as english"),
                DB::raw("MAX(CASE WHEN st.language_name = 'ps' THEN st.name END) as pashto")
            )
            ->groupBy('st.status_id')
            ->get();

        $scheduleItemsFromFrontend = $request->input('schedule_items');

        foreach ($scheduleItemsFromFrontend as $item) {
            // Get the organization_id by joining with the projects table
            $project = DB::table('projects as p')
                ->where('p.id', $item['project_id'])
                ->select('p.organization_id')
                ->first();

            if (!$project) {
                return response()->json(['message' => __('app_translation.project_not_found')], 404);
            }

            DB::table('schedule_items')
                ->where('schedule_id', $schedule->id)
                ->where('id', $item['id'])
                ->update([
                    'status_id' => $item['status']['id'],
                    'comment'   => $item['comment'] ?? null,
                ]);

            $statusTranslated = $statusTrans->firstWhere('status_id', $item['status']['id']);

            ProjectStatus::where('project_id', $item['project_id'])->update(['is_active' => false]);

            $newStatus = StatusEnum::approved->value;
            if ($item['status']['id'] == StatusEnum::has_comment->value) {
                $newStatus = StatusEnum::document_upload_required->value;
            } else if ($item['status']['id'] == StatusEnum::missed->value) {
                $newStatus = StatusEnum::pending_for_schedule->value;
            }
            ProjectStatus::create([
                'is_active' => true,
                'project_id' => $item['project_id'],
                'status_id' => $newStatus,
                'comment' => $item['comment'] ?? '',
                'userable_type' => $this->getModelName(get_class($authUser)),
                'userable_id' => $authUser->id,
            ]);

            // Get the organization_id from the project and send a notification
            $organizationId = $project->organization_id;

            $message = [
                'en' => Lang::get('app_translation.project_presentat_res', ['name' => $statusTranslated->english ?? 'Unknown User'], 'en'),
                'fa' => Lang::get('app_translation.project_presentat_res', ['name' => $statusTranslated->farsi ?? 'Unknown User'], 'fa'),
                'ps' => Lang::get('app_translation.project_presentat_res', ['name' => $statusTranslated->pashto ?? 'Unknown User'], 'ps'),
            ];

            $this->notificationRepository->sendStoreUniqueNotification(
                NotifierEnum::project_presentation_completed->value,
                $message,
                null,
                null,
                'projects',
                $organizationId // Send the notification to the organization
            );
        }

        DB::commit();
        return response()->json(['message' => __('app_translation.success')], 200);
    }


    public function store(ScheduleRequest $request)
    {
        DB::beginTransaction();
        $authUser = $request->user();
        // Create Schedule
        $schedule = Schedule::create([
            'date' => Carbon::parse($request['date'])->toDateString(),
            'start_time' => $request['start_time'] ?? '08:00',
            'end_time' => $request['end_time'],
            'representators_count' => $request['presentation_count'],
            'presentation_lenght' => $request['presentation_length'],
            'gap_between' => $request['gap_between'],
            'lunch_start' => $request['lunch_start'],
            'lunch_end' => $request['lunch_end'],
            'dinner_start' => $request['dinner_start'],
            'dinner_end' => $request['dinner_end'],
            'presentation_before_lunch' => $request['presentation_count'] - $request['presentations_after_lunch'],
            'presentation_after_lunch' => $request['presentations_after_lunch'],
            'is_hour_24' => $request['is_hour_24'] ?? false,
            'schedule_status_id' => ScheduleStatusEnum::Scheduled->value
        ]);

        $projectIds = [];
        $scheduleItemsData = [];

        foreach ($request['scheduleItems'] as $item) {
            // 1. Add schedule item
            $scheduleItem  = ScheduleItem::create([
                'project_id' => $item['projectId'],
                'schedule_id' => $schedule->id,
                'start_time' => $item['slot']['presentation_start'],
                'end_time' => $item['slot']['presentation_end'],
                'status_id' => StatusEnum::pending->value,
            ]);

            // 2. Add schedule item document
            if (!empty($item['attachment'])) {
                $attachment = $item['attachment'];
                $pendingId = $attachment['pending_id'];
                $this->storageRepository->scheduleDocumentStore($schedule->id, $pendingId, function ($docData) use (&$scheduleItem, &$pendingId) {
                    $document = Document::create([
                        'actual_name' => $docData['actual_name'],
                        'size' => $docData['size'],
                        'path' => $docData['path'],
                        'type' => $docData['type'],
                        'check_list_id' => $docData['check_list_id'],
                    ]);

                    ScheduleDocument::create([
                        'document_id' => $document->id,
                        'schedule_item_id' => $scheduleItem->id,
                    ]);
                });
                $this->pendingTaskRepository->destroyPendingTaskById($pendingId);
            }
            // 3. Change project status
            ProjectStatus::where('project_id', $item['projectId'])->update(['is_active' => false]);
            ProjectStatus::create([
                'is_active' => true,
                'project_id' => $item['projectId'],
                'status_id' => StatusEnum::scheduled->value,
                'comment' => 'Schedule for the presentation',
                'userable_type' => $this->getModelName(get_class($authUser)),
                'userable_id' => $authUser->id,
            ]);

            $projectIds[] = $item['projectId'];

            // Collect schedule item data for notifications
            $scheduleItemsData[$item['projectId']] = [
                'start_time' => $item['slot']['presentation_start'],
                'end_time' => $item['slot']['presentation_end'],
            ];
        }

        DB::commit();
        $scheduleDate = Carbon::parse($request['date'])->format('Y-m-d');
        $results = DB::table('projects as p')
            ->whereIn('p.id', $projectIds)
            ->leftJoin('project_trans as pt', 'pt.project_id', '=', 'p.id')
            ->select(
                'p.id as project_id',
                'p.organization_id',
                DB::raw("MAX(CASE WHEN pt.language_name = 'en' THEN pt.name END) as name_en"),
                DB::raw("MAX(CASE WHEN pt.language_name = 'fa' THEN pt.name END) as name_fa"),
                DB::raw("MAX(CASE WHEN pt.language_name = 'ps' THEN pt.name END) as name_ps")
            )
            ->groupBy('p.id', 'p.organization_id')
            ->get();

        // Index projects by project_id for easy access
        $projectsById = $results->keyBy('project_id');

        foreach ($scheduleItemsData as $projectId => $times) {
            if (!isset($projectsById[$projectId])) {
                continue; // Skip if project not found (just in case)
            }

            $project = $projectsById[$projectId];

            $startTime = Carbon::parse($times['start_time'])->format('g:i A');
            $endTime = Carbon::parse($times['end_time'])->format('g:i A');

            $message = [
                'en' => Lang::get(
                    'app_translation.project_schedule_to_present',
                    [
                        'username' => $project->name_en ?? 'Unknown Project',
                        'start_time' => $startTime,
                        'end_time' => $endTime,
                        'date' => $scheduleDate,
                    ],
                    'en'
                ),
                'fa' => Lang::get(
                    'app_translation.project_schedule_to_present',
                    [
                        'username' => $project->name_fa ?? 'Unknown Project',
                        'start_time' => $startTime,
                        'end_time' => $endTime,
                        'date' => $scheduleDate,
                    ],
                    'fa'
                ),
                'ps' => Lang::get(
                    'app_translation.project_schedule_to_present',
                    [
                        'username' => $project->name_ps ?? 'Unknown Project',
                        'start_time' => $startTime,
                        'end_time' => $endTime,
                        'date' => $scheduleDate,
                    ],
                    'ps'
                ),
            ];

            $this->notificationRepository->sendStoreUniqueNotification(
                NotifierEnum::project_scheduled_for_presentation->value,
                $message,
                null,
                null,
                'projects',
                $project->organization_id
            );
        }

        return response()->json(['message' => __('app_translation.success')], 200);
    }
    public function cancelSchedule($id)
    {
        $authUser = request()->user();
        $schedule = Schedule::find($id);

        if (!$schedule) {
            return response()->json(['message' => __('app_translation.schedule_not_found')], 404);
        }

        DB::beginTransaction();

        $scheduleItems = ScheduleItem::where('schedule_id', $id)
            ->join('projects as p', 'p.id', '=', 'schedule_items.project_id')
            ->select(
                'schedule_items.id',
                'schedule_items.project_id',
                'p.organization_id'
            )
            ->get();

        foreach ($scheduleItems as $item) {
            $scheduleDocument = ScheduleDocument::where('schedule_item_id', $item->id)
                ->select('id', 'document_id')
                ->first();

            if ($scheduleDocument) {
                $document = Document::where('id', $scheduleDocument->document_id)
                    ->select('id', 'path')
                    ->first();

                if ($document) {
                    $this->deleteDocument($this->transformToPrivate($document->path));
                    $document->delete();
                }

                $scheduleDocument->delete();
            }

            // Set current status to inactive
            ProjectStatus::where('project_id', $item->project_id)->update(['is_active' => false]);

            // Insert new status
            ProjectStatus::create([
                'is_active' => true,
                'project_id' => $item->project_id,
                'status_id' => StatusEnum::pending_for_schedule->value,
                'comment' => 'Presentation is canceled',
                'userable_type' => $this->getModelName(get_class($authUser)),
                'userable_id' => $authUser->id,
            ]);

            // Send notification
            $message = [
                'en' => Lang::get('app_translation.project_canceled_by_admin', [], 'en'),
                'fa' => Lang::get('app_translation.project_canceled_by_admin', [], 'fa'),
                'ps' => Lang::get('app_translation.project_canceled_by_admin', [], 'ps'),
            ];

            $this->notificationRepository->sendStoreUniqueNotification(
                NotifierEnum::project_presentation_canceled->value,
                $message,
                null,
                null,
                'projects',
                $item->organization_id
            );

            // Delete schedule item
            ScheduleItem::where('id', $item->id)->delete(); // safer if $item is a custom stdClass
        }

        $schedule->delete();

        DB::commit();

        return response()->json(['message' => __('app_translation.success')], 200);
    }


    public function edit($id)
    {
        $locale = App::getLocale();

        // Fetch the schedule
        $schedule = DB::table('schedules')->where('id', $id)->first();
        if (!$schedule) {
            return response()->json(['message' => __('app_translation.schedule_not_found')], 404);
        }

        // Fetch schedule items with project names
        $scheduleItems = DB::table('schedule_items as schi')
            ->join('project_trans as prot', function ($join) use ($locale) {
                $join->on('schi.project_id', '=', 'prot.project_id')
                    ->where('prot.language_name', $locale);
            })
            ->where('schedule_id', $id)
            ->select('prot.name as project_name', 'schi.*')
            ->get();

        // Get all schedule item IDs
        $itemIds = $scheduleItems->pluck('id')->all();

        // Fetch all schedule documents for these items
        $scheduleDocuments = DB::table('schedule_documents')
            ->whereIn('schedule_item_id', $itemIds)
            ->get()
            ->keyBy('schedule_item_id');

        // Get all document IDs linked to these schedule documents
        $documentIds = $scheduleDocuments->pluck('document_id')->all();

        // Fetch all documents in one query
        $documents = DB::table('documents')
            ->whereIn('id', $documentIds)
            ->get()
            ->keyBy('id');

        $formattedItems = [];
        $projects = [];
        $specialProjects = [];

        foreach ($scheduleItems as $item) {
            $document = null;

            // Check if this schedule item has a document
            if (isset($scheduleDocuments[$item->id])) {
                $docId = $scheduleDocuments[$item->id]->document_id;

                if (isset($documents[$docId])) {
                    $doc = $documents[$docId];
                    $document = [
                        'document_id' => $doc->id,
                        'name' => $doc->actual_name,
                        'size' => $doc->size,
                        'check_list_id' => $doc->check_list_id,
                        'extension' => $doc->type,
                        'path' => $doc->path,
                    ];
                }
            }

            // Calculate gap_end
            $gapEnd = Carbon::parse($item->end_time)
                ->addMinutes($schedule->gap_between)
                ->format('H:i');

            // Format schedule item for response
            $formattedItems[] = [
                'slot' => [
                    'id' => $item->id,
                    'presentation_start' => $item->start_time,
                    'presentation_end' => $item->end_time,
                    'gap_end' => $gapEnd,
                ],
                'projectId' => $item->project_id,
                'project_name' => $item->project_name,
                'attachment' => $document,
                'selected' => false,
            ];

            // Build projects array (like React)
            $proj = [
                'id' => $item->project_id,
                'name' => $item->project_name,
                'attachment' => $document,
                'selected' => true,
            ];
            $projects[] = $proj;

            // Build special_projects for those with attachments
            if ($document) {
                $specialProjects[] = [
                    'project' => ['id' => $proj['id'], 'name' => $proj['name']],
                    'attachment' => $document,
                ];
            }
        }

        // Final data structure with projects & special_projects included
        $dateToCheck = Carbon::parse($schedule->date);
        $today = Carbon::today();

        $data = [
            'id' => $schedule->id,
            "schedule_status_id" => $schedule->schedule_status_id,
            'date' => $schedule->date,
            'passed' => $dateToCheck->lessThan($today) ? true : false,
            'start_time' => $schedule->start_time,
            'end_time' => $schedule->end_time,
            'dinner_start' => $schedule->dinner_start,
            'dinner_end' => $schedule->dinner_end,
            'gap_between' => $schedule->gap_between,
            'lunch_start' => $schedule->lunch_start,
            'lunch_end' => $schedule->lunch_end,
            'presentation_length' => $schedule->presentation_lenght,
            'presentation_count' => $schedule->representators_count,
            'presentations_after_lunch' => $schedule->presentation_after_lunch,
            'is_hour_24' => (bool) $schedule->is_hour_24,
            'scheduleItems' => $formattedItems,
            'projects' => $projects,
            'special_projects' => $specialProjects,
        ];

        return response()->json($data);
    }

    public function update(ScheduleRequest $request)
    {
        DB::beginTransaction();

        $authUser = $request->user();
        $id = $request['id'];

        // Fetch existing schedule manually
        $schedule = DB::table('schedules')->where('id', $id)->first();
        if (!$schedule) {
            return response()->json(['message' => __('app_translation.schedule_not_found')], 404);
        }

        // Update schedule details
        DB::table('schedules')->where('id', $id)->update([
            'date' => Carbon::parse($request['date'])->toDateString(),
            'start_time' => $request['start_time'] ?? '08:00',
            'end_time' => $request['end_time'],
            'representators_count' => $request['presentation_count'],
            'presentation_lenght' => $request['presentation_length'],
            'gap_between' => $request['gap_between'],
            'lunch_start' => $request['lunch_start'],
            'lunch_end' => $request['lunch_end'],
            'dinner_start' => $request['dinner_start'],
            'dinner_end' => $request['dinner_end'],
            'presentation_before_lunch' => $request['presentations_before_lunch'],
            'presentation_after_lunch' => $request['presentations_after_lunch'],
            'is_hour_24' => $request['is_hour_24'] ?? false,
        ]);

        // Retrieve existing schedule items
        $existingItems = DB::table('schedule_items')->where('schedule_id', $id)->get();
        $existingItemIds = $existingItems->pluck('id')->toArray();
        $oldProjectIds = $existingItems->pluck('project_id')->toArray();

        $newProjectIds = [];
        $receivedItemIds = [];

        foreach ($request['scheduleItems'] as $item) {
            $newProjectIds[] = $item['projectId'];

            // Update or insert schedule item
            if (!empty($item['slot']['id']) && in_array($item['slot']['id'], $existingItemIds)) {
                DB::table('schedule_items')->where('id', $item['slot']['id'])->update([
                    'project_id' => $item['projectId'],
                    'start_time' => $item['slot']['presentation_start'],
                    'end_time' => $item['slot']['presentation_end'],
                ]);
                $scheduleItemId = $item['slot']['id'];
            } else {
                $scheduleItemId = DB::table('schedule_items')->insertGetId([
                    'project_id' => $item['projectId'],
                    'schedule_id' => $id,
                    'start_time' => $item['slot']['presentation_start'],
                    'end_time' => $item['slot']['presentation_end'],
                ]);
            }

            $receivedItemIds[] = $scheduleItemId;

            // Handle attachment if present
            if (!empty($item['attachment'])) {
                $pendingId = $item['attachment']['pending_id'] ?? null;
                $existingScheduleDoc = DB::table('schedule_documents')
                    ->where('schedule_item_id', $scheduleItemId)
                    ->first();

                if ($pendingId) {
                    $this->storageRepository->scheduleDocumentStore($id, $pendingId, function ($docData) use ($scheduleItemId, $existingScheduleDoc) {
                        $docId = DB::table('documents')->insertGetId([
                            'actual_name' => $docData['actual_name'],
                            'size' => $docData['size'],
                            'path' => $docData['path'],
                            'type' => $docData['type'],
                            'check_list_id' => $docData['check_list_id'],
                        ]);

                        if ($existingScheduleDoc) {
                            DB::table('schedule_documents')
                                ->where('schedule_item_id', $scheduleItemId)
                                ->update(['document_id' => $docId]);
                        } else {
                            DB::table('schedule_documents')->insert([
                                'document_id' => $docId,
                                'schedule_item_id' => $scheduleItemId,
                            ]);
                        }
                    });

                    $this->pendingTaskRepository->destroyPendingTaskById($pendingId);
                }
            }

            // Update project status
            DB::table('project_statuses')->where('project_id', $item['projectId'])->update(['is_active' => false]);
            DB::table('project_statuses')->insert([
                'is_active' => true,
                'project_id' => $item['projectId'],
                'status_id' => StatusEnum::scheduled->value,
                'comment' => 'Schedule updated for the presentation',
                'userable_type' => $this->getModelName(get_class($authUser)),
                'userable_id' => $authUser->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Delete schedule items no longer included
        $itemsToDelete = array_diff($existingItemIds, $receivedItemIds);
        if (!empty($itemsToDelete)) {
            DB::table('schedule_items')->whereIn('id', $itemsToDelete)->delete();
        }

        // Remove schedule_documents entries only for removed projects
        $removedProjectIds = array_diff($oldProjectIds, $newProjectIds);
        if (!empty($removedProjectIds)) {
            // Get schedule_item IDs for removed projects
            $scheduleItemIdsToClean = DB::table('schedule_items')
                ->where('schedule_id', $id)
                ->whereIn('project_id', $removedProjectIds)
                ->pluck('id')
                ->toArray();

            if (!empty($scheduleItemIdsToClean)) {
                DB::table('schedule_documents')
                    ->whereIn('schedule_item_id', $scheduleItemIdsToClean)
                    ->delete();
            }

            // Update project statuses for removed projects
            foreach ($removedProjectIds as $pid) {
                DB::table('project_statuses')->where('project_id', $pid)->update(['is_active' => false]);
                DB::table('project_statuses')->insert([
                    'is_active' => true,
                    'project_id' => $pid,
                    'status_id' => StatusEnum::pending_for_schedule->value,
                    'comment' => 'Project removed from schedule',
                    'userable_type' => $this->getModelName(get_class($authUser)),
                    'userable_id' => $authUser->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        DB::commit();

        return response()->json(['message' => __('app_translation.success')], 200);
    }
    public function present($id)
    {
        $locale = App::getLocale();

        // Fetch the schedule
        $schedule = DB::table('schedules as s')
            ->where('s.id', $id)
            ->join('schedule_status_trans as sst', function ($join) use ($locale) {
                $join->on('sst.schedule_status_id', '=', 's.schedule_status_id')
                    ->where('sst.language_name', $locale);
            })
            ->select(
                's.date',
                's.start_time',
                's.end_time',
                's.gap_between',
                's.lunch_start',
                's.lunch_end',
                'sst.value as schedule_status',
                's.schedule_status_id'
            )
            ->first();

        if (!$schedule) {
            return response()->json(['message' => __('app_translation.not_found')], 404);
        }

        // Fetch schedule items with project names
        $rawData = DB::table('schedule_items as si')
            ->where('si.schedule_id', $id)
            ->join('status_trans as st', function ($join) use ($locale) {
                $join->on('st.status_id', '=', 'si.status_id')
                    ->where('st.language_name', $locale);
            })
            ->join('project_trans as pt', function ($join) use ($locale) {
                $join->on('pt.project_id', '=', 'si.project_id')
                    ->where('pt.language_name', $locale);
            })
            ->leftJoin('project_documents as pd', 'pd.project_id', '=', 'si.project_id')
            ->leftJoin('documents as d', 'd.id', '=', 'pd.document_id')
            ->leftJoin('check_list_trans as clt', function ($join) use ($locale) {
                $join->on('clt.check_list_id', '=', 'd.check_list_id')
                    ->where('clt.language_name', $locale);
            })
            ->select(
                'si.id as schedule_item_id',
                'si.start_time',
                'si.end_time',
                'si.comment',
                'pt.project_id',
                'pt.name as project_name',
                'si.status_id',
                'st.name as status',
                'd.actual_name',
                'd.size',
                'd.path',
                'd.type',
                'clt.value as checklist'
            )
            ->get();

        $scheduleItems = [];

        foreach ($rawData as $row) {
            $id = $row->schedule_item_id;

            if (!isset($scheduleItems[$id])) {
                // Initialize schedule item entry
                $scheduleItems[$id] = [
                    'id' => $row->schedule_item_id,
                    'comment' => $row->comment,
                    'start_time' => $row->start_time,
                    'end_time' => $row->end_time,
                    'project_id' => $row->project_id,
                    'project_name' => $row->project_name,
                    'status' => ['id' => $row->status_id, 'name' => $row->status,],
                    'documents' => [],
                ];
            }

            // Only add document if it exists (handle leftJoin nulls)
            if ($row->actual_name !== null) {
                $scheduleItems[$id]['documents'][] = [
                    'name' => $row->actual_name,
                    'size' => $row->size,
                    'path' => $row->path,
                    'type' => $row->type,
                    'checklist' => $row->checklist,
                ];
            }
        }
        $schedule->schedule_items = array_values($scheduleItems);

        return response()->json($schedule);
    }
}
