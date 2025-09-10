<?php

namespace App\Http\Controllers\v1\template;

use App\Models\CheckList;
use Illuminate\Http\Request;
use App\Models\CheckListType;
use App\Models\CheckListTrans;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\App;
use App\Http\Controllers\Controller;
use App\Enums\Languages\LanguageEnum;
use Illuminate\Support\Facades\Cache;
use App\Enums\Checklist\ChecklistEnum;
use App\Enums\Types\CheckListTypeEnum;
use App\Http\Requests\v1\checklist\StoreCheckListRequest;

class CheckListController extends Controller
{

    private $cacheChecklist = 'checklist_office';

    public function __construct()
    {
        $this->cacheChecklist = 'checklist_office_';
    }
    public function index()
    {
        $locale = App::getLocale();

        $tr =  Cache::remember($this->cacheChecklist . '_' . $locale, 10, function () use ($locale) {

            return  DB::table('check_lists as cl')
                ->join('users as u', 'u.id', '=', 'cl.user_id')
                ->whereIn('cl.check_list_type_id', [
                    CheckListTypeEnum::organization_registeration->value,
                    CheckListTypeEnum::project_registeration->value
                ])
                ->join('check_list_trans as clt', 'clt.check_list_id', '=', 'cl.id')
                ->where('clt.language_name', $locale)
                ->join('check_list_types as cltt', 'cltt.id', '=', 'cl.check_list_type_id')

                ->join('check_list_type_trans as clttt', 'clttt.check_list_type_id', '=', 'cltt.id')
                ->where('clttt.language_name', $locale)
                ->select(
                    'clt.value as name',
                    'cl.id',
                    'cl.acceptable_mimes',
                    'cl.acceptable_extensions',
                    'cl.description',
                    'cl.active',
                    'clttt.value as type',
                    'cltt.id as type_id',
                    'u.username as saved_by',
                    'cl.created_at'
                )
                ->orderBy('cltt.id')
                ->get();
        });

        return response()->json(
            $tr,
            200,
            [],
            JSON_UNESCAPED_UNICODE
        );
    }
    public function store(StoreCheckListRequest $request)
    {
        $request->validated();
        $authUser = $request->user();
        $convertedMimes = [];
        $convertedAccept = [];
        $convertedExtensions = [];
        foreach ($request->file_type as $extension) {
            $convertedMimes[] = $extension['frontEndName'];
            $convertedAccept[] = $extension['frontEndInput'];
            $convertedExtensions[] = $extension['name'];
        }

        $checklist = CheckList::create([
            "check_list_type_id" => $request->type['id'],
            "active" => $request->status,
            "file_size" => $request->file_size,
            "description" => $request->detail,
            "user_id" => $authUser->id,
            "acceptable_extensions" => implode(',', $convertedExtensions),
            "acceptable_mimes" => implode(',', $convertedMimes),
            "accept" => implode(',', $convertedAccept),
        ]);
        foreach (LanguageEnum::LANGUAGES as $code => $name) {
            CheckListTrans::create([
                "value" => $request["name_{$name}"],
                "check_list_id" => $checklist->id,
                "language_name" => $code,
            ]);
        }
        $locale = App::getLocale();
        $name = $request->name_english;
        if ($locale == LanguageEnum::farsi->value) {
            $name = $request->name_farsi;
        } else {
            $name = $request->name_pashto;
        }
        $tr =  [
            "id" => $checklist->id,
            "name" => $name,
            "description" => $checklist->description,
            "active" => $request->status,
            "type" => $request->type['name'],
            "type_id" => $request->type['id'],
            "saved_by" => $authUser->username,
            "created_at" => $checklist->created_at,
        ];
        Cache::forget($this->cacheChecklist . '_' . $locale);
        return response()->json(
            [
                "checklist" => $tr,
                'message' => __('app_translation.success'),
            ],
            200,
            [],
            JSON_UNESCAPED_UNICODE
        );
    }
    public function update(StoreCheckListRequest $request)
    {
        $request->validated();
        $request->validate([
            'id' => "required"
        ]);
        $authUser = $request->user();
        $id = $request->id;
        $checklist = CheckList::find($id);
        if (!$checklist) {
            return response()->json([
                'message' => __('app_translation.checklist_not_found')
            ], 404, [], JSON_UNESCAPED_UNICODE);
        }
        $type = CheckListType::find($request->type['id']);
        if (!$type) {
            return response()->json([
                'message' => __('app_translation.checklist_type_not_found')
            ], 404, [], JSON_UNESCAPED_UNICODE);
        }
        // Begin transaction
        DB::beginTransaction();
        // 1. Update checklist
        $convertedMimes = [];
        $convertedExtensions = [];
        foreach ($request->file_type as $extension) {
            $convertedMimes[] = $extension['frontEndName'];
            $convertedAccept[] = $extension['frontEndInput'];
            $convertedExtensions[] = $extension['name'];
        }

        $checklist->acceptable_mimes = implode(',', $convertedMimes);
        $checklist->accept = implode(',', $convertedAccept);
        $checklist->acceptable_extensions = implode(',', $convertedExtensions);
        $checklist->check_list_type_id = $type->id;
        $checklist->active = $request->status;
        $checklist->file_size = $request->file_size;
        $checklist->description = $request->detail;
        $checklist->user_id  = $authUser->id;
        $checklist->save();
        // 2. Update translations
        $trans = CheckListTrans::where('check_list_id', $checklist->id)
            ->select('language_name', 'value', 'id')
            ->get();
        foreach (LanguageEnum::LANGUAGES as $code => $name) {
            $translation = $trans->where('language_name', $code)->first();
            if ($translation) {
                $translation->value = $request["name_{$name}"];
                $translation->save();
            } else {
                return response()->json([
                    'message' => __('app_translation.failed')
                ], 500, [], JSON_UNESCAPED_UNICODE);
            }
        }

        DB::commit();
        $locale = App::getLocale();
        Cache::forget($this->cacheChecklist . '_' . $locale);

        $name = $request->name_english;
        if ($locale == LanguageEnum::farsi->value) {
            $name = $request->name_farsi;
        } else if ($locale == LanguageEnum::pashto->value) {
            $name = $request->name_pashto;
        }
        $tr =  [
            "id" => $checklist->id,
            "name" => $name,
            "description" => $checklist->description,
            "active" => $checklist->active,
            "type" => $request->type['name'],
            "type_id" => $request->type['id'],
            "saved_by" => $authUser->username,
            "created_at" => $checklist->created_at,
        ];
        return response()->json([
            'checklist' => $tr,
            'message' => __('app_translation.success'),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }
    public function destroy($id)
    {
        CheckList::find($id)->delete();
        Cache::forget($this->cacheChecklist);

        return response()->json(
            [
                'message' => __('app_translation.success'),
            ],
            200,
            [],
            JSON_UNESCAPED_UNICODE
        );
    }
    public function checklistTypes()
    {
        $locale = App::getLocale();
        $tr =  DB::table('check_list_types as clt')
            ->whereIn('clt.id', [
                CheckListTypeEnum::organization_registeration->value,
                CheckListTypeEnum::project_registeration->value
            ])
            ->join('check_list_type_trans as cltt', 'cltt.check_list_type_id', '=', 'clt.id')
            ->where('cltt.language_name', $locale)
            ->select(
                'cltt.value as name',
                'clt.id',
            )
            ->orderBy('clt.id')
            ->get();

        return response()->json(
            $tr,
            200,
            [],
            JSON_UNESCAPED_UNICODE
        );
    }
    public function edit($id)
    {
        $locale = App::getLocale();
        $checklist = DB::table('check_lists as cl')
            ->where('cl.id', $id)
            ->leftJoin('check_list_trans as clt_farsi', function ($join) {
                $join->on('clt_farsi.check_list_id', '=', 'cl.id')
                    ->where('clt_farsi.language_name', 'fa'); // Join for Farsi (fa)
            })
            ->leftJoin('check_list_trans as clt_english', function ($join) {
                $join->on('clt_english.check_list_id', '=', 'cl.id')
                    ->where('clt_english.language_name', 'en'); // Join for English (en)
            })
            ->leftJoin('check_list_trans as clt_pashto', function ($join) {
                $join->on('clt_pashto.check_list_id', '=', 'cl.id')
                    ->where('clt_pashto.language_name', 'ps'); // Join for Pashto (ps)
            })
            ->join('check_list_types as cltt', 'cltt.id', '=', 'cl.check_list_type_id')
            ->join('check_list_type_trans as clttt', 'clttt.check_list_type_id', '=', 'cltt.id')
            ->where('clttt.language_name', $locale)
            ->select(
                'cl.id',
                'cl.acceptable_mimes',
                'cl.accept',
                'cl.acceptable_extensions',
                'cl.description',
                'cl.active as status',
                'cl.file_size',
                'clttt.value as type',
                'cltt.id as type_id',
                'clt_farsi.value as name_farsi', // Farsi translation
                'clt_english.value as name_english', // English translation
                'clt_pashto.value as name_pashto',
                'cl.created_at'
            )
            ->orderBy('cltt.id')
            ->first();

        // Check if acceptable_mimes and acceptable_extensions are present
        if ($checklist) {
            // Exploding the comma-separated strings into arrays
            $acceptableMimes = explode(',', $checklist->acceptable_mimes);
            $acceptableExtensions = explode(',', $checklist->acceptable_extensions);
            $acceptableAccept = explode(',', $checklist->accept);

            // Combine them into an array of objects
            $combined = [];
            foreach ($acceptableMimes as $index => $mime) {
                // Assuming the index of mimes matches with extensions
                if (isset($acceptableExtensions[$index])) {
                    $combined[] = [
                        'name' => $acceptableExtensions[$index],
                        "label" => $mime,
                        'frontEndName' => $mime,
                        'frontEndInput' => $acceptableAccept[$index],
                    ];
                }
            }

            // Assign the combined array to the checklist object
            $checklist->file_type = $combined;
        }
        $checklist->status = (bool) $checklist->status;
        // Remove unwanted data from the checklist
        unset($checklist->acceptable_mimes);
        unset($checklist->acceptable_extensions);
        unset($checklist->accept);
        $tr =  [
            "id" => $checklist->id,
            "name_farsi" => $checklist->name_farsi,
            "name_english" => $checklist->name_english,
            "name_pashto" => $checklist->name_pashto,
            "detail" => $checklist->description,
            "file_type" => $checklist->file_type,
            "type" => [
                'id' => $checklist->type_id,
                'name' => $checklist->type
            ],
            "status" => $checklist->status,
            "file_size" => $checklist->file_size,
            "created_at" => $checklist->created_at,
        ];
        return response()->json(
            $tr,
            200,
            [],
            JSON_UNESCAPED_UNICODE
        );
    }
    // Application
    public function organizationRegister()
    {
        $exclude = [
            ChecklistEnum::director_work_permit->value,
            CheckListEnum::organization_representor_letter->value,
            CheckListEnum::organization_register_form_en->value,
            CheckListEnum::organization_register_form_fa->value,
            CheckListEnum::organization_register_form_ps->value,
        ];
        return response()->json([
            'checklist' => $this->getChecklistsWithExclude($exclude, CheckListTypeEnum::organization_registeration->value)
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }
    public function organizationRegisterAbroadDirector()
    {
        $exclude = [
            CheckListEnum::organization_representor_letter->value,
            CheckListEnum::organization_register_form_en->value,
            CheckListEnum::organization_register_form_fa->value,
            CheckListEnum::organization_register_form_ps->value,
        ];
        return response()->json([
            'checklist' => $this->getChecklistsWithExclude($exclude, CheckListTypeEnum::organization_registeration->value)
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }
    public function organizationExtend(Request $request)
    {
        $new_director = $request->query('n_dir');
        $new_representor = $request->query('n_r');
        $exclude = [
            CheckListEnum::director_work_permit->value,
            CheckListEnum::organization_register_form_en->value,
            CheckListEnum::organization_register_form_fa->value,
            CheckListEnum::organization_register_form_ps->value,
        ];
        if ($new_director == 'false') {
            array_push($exclude, CheckListEnum::director_nid->value);
        }
        if ($new_representor == 'false') {
            array_push($exclude, CheckListEnum::organization_representor_letter->value);
        }

        return response()->json([
            'checklist' => $this->getChecklistsWithExclude($exclude, CheckListTypeEnum::organization_registeration->value),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }
    public function organizationExtendAbroadDirector(Request $request)
    {
        $new_director = $request->query('n_dir');
        $new_representor = $request->query('n_r');
        $exclude = [
            ChecklistEnum::director_work_permit->value,
            CheckListEnum::organization_register_form_en->value,
            CheckListEnum::organization_register_form_fa->value,
            CheckListEnum::organization_register_form_ps->value,
        ];
        if ($new_director == 'false') {
            array_push($exclude, CheckListEnum::director_nid->value);
        }
        if ($new_representor == 'false') {
            array_push($exclude, CheckListEnum::organization_representor_letter->value);
        }
        return response()->json([
            'checklist' => $this->getChecklistsWithExclude($exclude, CheckListTypeEnum::organization_registeration->value)
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }
    public function projectRegisteration()
    {
        $exclude = [
            CheckListEnum::mou_en->value,
            CheckListEnum::mou_fa->value,
            CheckListEnum::mou_ps->value,
            CheckListEnum::project_presentation->value,
        ];
        return response()->json([
            'checklist' => $this->getChecklistsWithExclude($exclude, CheckListTypeEnum::project_registeration->value)
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }
    public function commonChecklist($id)
    {
        $checklist = CheckList::where('id', $id)
            ->select(
                'id',
                'acceptable_extensions',
                'acceptable_mimes',
                'file_size',
            )->first();
        return response()->json(
            $checklist,
            200,
            [],
            JSON_UNESCAPED_UNICODE
        );
    }
    public function missingRegisterSignedForm()
    {
        $includes = [
            CheckListEnum::organization_register_form_en->value,
            CheckListEnum::organization_register_form_fa->value,
            CheckListEnum::organization_register_form_ps->value
        ];
        return response()->json([
            'checklist' => $this->getChecklistsWithInclude($includes, CheckListTypeEnum::organization_registeration->value)
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }
    public function missingMouSignedForm()
    {
        $includes = [
            CheckListEnum::mou_en->value,
            CheckListEnum::mou_fa->value,
            CheckListEnum::mou_ps->value
        ];
        return response()->json([
            'checklist' => $this->getChecklistsWithInclude($includes, CheckListTypeEnum::project_registeration->value)
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }
    public function getChecklistsWithExclude($exclude, $type)
    {
        $locale = App::getLocale();
        return DB::table('check_lists as cl')
            ->where('cl.active', true)
            ->where('cl.check_list_type_id', $type)
            ->whereNotIn('cl.id', $exclude)
            ->join('check_list_trans as clt', 'clt.check_list_id', '=', 'cl.id')
            ->where('clt.language_name', $locale)
            ->select(
                'clt.value as name',
                'cl.id',
                'cl.file_size',
                'cl.acceptable_mimes',
                'cl.accept',
                'cl.acceptable_extensions',
                'cl.description'
            )
            ->orderBy('cl.id')
            ->get();
    }
    public function getChecklistsWithInclude($include, $type)
    {
        $locale = App::getLocale();
        return DB::table('check_lists as cl')
            ->where('cl.active', true)
            ->where('cl.check_list_type_id', $type)
            ->whereIn('cl.id', $include)
            ->join('check_list_trans as clt', 'clt.check_list_id', '=', 'cl.id')
            ->where('clt.language_name', $locale)
            ->select(
                'clt.value as name',
                'cl.id',
                'cl.file_size',
                'cl.accept',
                'cl.acceptable_mimes',
                'cl.acceptable_extensions',
                'cl.description'
            )
            ->orderBy('cl.id')
            ->get();
    }

    public function representativeChecklist()
    {
        $checklist = DB::table('check_lists as cl')
            ->where('cl.id', ChecklistEnum::organization_representor_letter->value)
            ->where('cl.active', true)
            ->select(
                'cl.id',
                'cl.acceptable_mimes',
                'cl.acceptable_extensions',
                'cl.description',
                'cl.file_size',
            )
            ->first();
        return response()->json(
            $checklist,
            200,
            [],
            JSON_UNESCAPED_UNICODE
        );
    }
    public function deputyDocChecklist()
    {
        $checklist = DB::table('check_lists as cl')
            ->where('cl.id', ChecklistEnum::schedule_deputy_document->value)
            ->where('cl.active', true)
            ->select(
                'cl.id',
                'cl.acceptable_mimes',
                'cl.acceptable_extensions',
                'cl.description',
                'cl.file_size',
            )
            ->first();
        return response()->json(
            $checklist,
            200,
            [],
            JSON_UNESCAPED_UNICODE
        );
    }
}
