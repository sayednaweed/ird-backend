<?php

namespace App\Http\Requests\v1\organization;

use App\Models\Organization;
use Illuminate\Support\Facades\Log;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrganizationInfoUpdateRequest extends FormRequest
{
    /**
     * Prepare the data for validation.
     *
     * This method is called before the validation rules are applied.
     *
     * @return void
     */
    protected function prepareForValidation()
    {
        // Get the raw contents field from the request (this is your JSON string)
        $jsonData = $this->input('contents');

        // Decode the JSON string into an array
        if ($jsonData) {
            $decodedData = json_decode($jsonData, true);

            // If the JSON is valid, merge the decoded data into the request
            if (json_last_error() === JSON_ERROR_NONE) {
                $this->merge($decodedData);
            } else {
                // Log or handle error if JSON is invalid
                Log::error('Invalid JSON data received', ['data' => $jsonData]);
            }
        }
    }
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize()
    {
        return true; // Ensure this is set according to your authentication logic
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules()
    {
        $id = $this->input('id'); // now merged from JSON

        return [
            'id' => 'required',
            'name_english' => 'required|string|max:100',
            'name_pashto' => 'required|string|max:100',
            'name_farsi' => 'required|string|max:100',

            'abbr' => [
                'required',
                'alpha', // only letters
                'max:50',
                Rule::unique('organizations', 'abbr')->ignore($id),
            ],
            "establishment_date" => "required",
            "province.id" => "required|exists:provinces,id",
            "district.id" => "required|exists:districts,id",
            "area_english" => "required|max:128|min:5",
            "area_pashto" => "required|max:128|min:5",
            "area_farsi" => "required|max:128|min:5",
            "contact" => "required",
            'email' => [
                'required',
                'email:rfc,filter',
                Rule::unique('emails', 'value')->ignore(
                    Organization::find($this->id)?->email_id, // the email record id
                    'id' // primary key in emails table
                )
            ],
        ];
    }

    /**
     * Get custom messages for validation errors.
     */
}
