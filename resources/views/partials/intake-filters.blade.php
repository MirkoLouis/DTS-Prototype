{{-- This partial represents the filter controls for the intake table. --}}
{{-- It's designed to be included in the main intake view and updated via AJAX. --}}
<form id="intake-filters-form" class="mb-4">
    <div class="flex flex-col md:flex-row gap-4 items-center">
        
        {{-- Purpose Dropdown --}}
        <div class="w-full md:w-auto">
            <label for="filter-purpose" class="sr-only">Filter by Purpose</label>
            <select id="filter-purpose" name="purpose" class="block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                <option value="">All Purposes</option>
                @foreach($purposes as $purpose)
                    <option value="{{ $purpose->id }}" {{ request('purpose') == $purpose->id ? 'selected' : '' }}>
                        {{ $purpose->name }}
                    </option>
                @endforeach
            </select>
        </div>

        {{-- Submitter Dropdown --}}
        <div class="w-full md:w-auto">
            <label for="filter-submitter" class="sr-only">Filter by Submitter</label>
            <select id="filter-submitter" name="submitter" class="block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                <option value="">All Submitters</option>
                @foreach($submitters as $submitter)
                    <option value="{{ $submitter }}" {{ request('submitter') == $submitter ? 'selected' : '' }}>
                        {{ $submitter }}
                    </option>
                @endforeach
            </select>
        </div>

        {{-- Clear Button --}}
        <div class="w-full md:w-auto">
            <button type="button" id="clear-filters-btn" class="w-full justify-center inline-flex items-center px-4 py-2 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-500 rounded-md font-semibold text-xs text-gray-700 dark:text-gray-300 uppercase tracking-widest shadow-sm hover:bg-gray-50 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 disabled:opacity-25 transition ease-in-out duration-150">
                Clear
            </button>
        </div>
    </div>
</form>
