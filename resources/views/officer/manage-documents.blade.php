<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            Manage Document Route: {{ $document->tracking_code }}
        </h2>
    </x-slot>

    <div class="py-2">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        
                        {{-- Document Details --}}
                        <div>
                            <h3 class="text-lg font-bold mb-2">Document Details</h3>
                            <p><strong>Submitter:</strong> {{ $document->guest_info['name'] }} ({{ $document->guest_info['email'] }})</p>
                            <p><strong>Purpose:</strong> {{ $document->purpose->name }}</p>
                            <p><strong>Submitted:</strong> {{ $document->created_at->format('M d, Y h:i A') }}</p>
                            <p><strong>Status:</strong> <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">{{ ucfirst($document->status) }}</span></p>
                        </div>

                        {{-- Route Management Form --}}
                        <div>
                            <h3 class="text-lg font-bold mb-2">Manage Route</h3>
                            <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">
                                Drag and drop the boxes to re-order them, or add a new step from the dropdown below.
                            </p>
                            <form id="route-form" action="{{ route('documents.finalize', $document->tracking_code) }}" method="POST">
                                @csrf
                                <input type="hidden" name="final_route" id="final_route">

                                {{-- Horizontal Draggable List --}}
                                <div class="overflow-x-auto pb-4">
                                    <div id="route-list" class="flex space-x-4 min-h-[8rem] bg-gray-50 dark:bg-gray-900 p-2 rounded-md">
                                        @foreach($document->purpose->suggested_route as $index => $step)
                                            <div class="route-step flex-shrink-0 w-40 p-4 bg-white dark:bg-gray-700 rounded-lg shadow-md cursor-move text-center">
                                                <button type="button" class="delete-step-btn w-6 h-6 bg-red-500 text-white rounded-full flex items-center justify-center text-xl" style="position: absolute; top: -0.25rem; right: -0.25rem;">&times;</button>
                                                <div class="font-bold text-lg text-indigo-600 dark:text-indigo-400">{{ $index + 1 }}</div>
                                                <div class="step-name text-sm mt-1">{{ $step }}</div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>

                                {{-- Add New Step UI --}}
                                <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                                    <label for="department-select" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Add New Step</label>
                                    <div class="mt-1 flex rounded-md shadow-sm">
                                        <select id="department-select" class="block w-full rounded-none rounded-l-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                                            <option disabled selected>Choose a department...</option>
                                            @foreach($departments as $department)
                                                <option value="{{ $department->name }}">{{ $department->name }}</option>
                                            @endforeach
                                        </select>
                                        <button type="button" id="add-step-btn" class="relative -ml-px inline-flex items-center space-x-2 rounded-r-md border border-gray-300 bg-gray-50 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600 dark:border-gray-600">
                                            <span>Add</span>
                                        </button>
                                    </div>
                                </div>
                                <div class="mt-6">
                                     <button type="submit" class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-500 active:bg-indigo-700 focus:outline-none focus:border-indigo-700 focus:ring focus:ring-200 disabled:opacity-25 transition">
                                        Accept & Finalize Route
                                    </button>
                                </div>
                            </form>

                            <div class="mt-6 flex items-center space-x-4">
                                {{-- New Decline button that opens modal --}}
                                <button type="button" id="open-decline-modal-btn" class="inline-flex items-center px-4 py-2 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-500 active:bg-red-700 focus:outline-none focus:border-red-700 focus:ring focus:ring-red-200 disabled:opacity-25 transition">
                                    Decline
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Decline Modal --}}
    <div id="decline-modal" class="fixed z-10 inset-0 overflow-y-auto hidden" aria-labelledby="modal-title" role="dialog" aria-modal="true">
        <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true"></div>
            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
            <div class="inline-block align-bottom bg-white dark:bg-gray-800 rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                <form id="decline-form" action="{{ route('documents.decline', $document->tracking_code) }}" method="POST">
                    @csrf
                    <div class="bg-white dark:bg-gray-800 px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                        <div class="sm:flex sm:items-start">
                            <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-red-100 sm:mx-0 sm:h-10 sm:w-10">
                                <svg class="h-6 w-6 text-red-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                </svg>
                            </div>
                            <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left w-full">
                                <h3 class="text-lg leading-6 font-medium text-gray-900 dark:text-gray-100" id="modal-title">
                                    Decline Document
                                </h3>
                                <div class="mt-2">
                                    <p class="text-sm text-gray-500 dark:text-gray-400">
                                        Please provide a reason for declining this document. This reason will be logged and may be visible to the guest.
                                    </p>
                                    <textarea id="decline_reason" name="decline_reason" rows="4" class="mt-2 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" required></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="bg-gray-50 dark:bg-gray-700 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                        <button type="submit" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-red-600 text-base font-medium text-white hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 sm:ml-3 sm:w-auto sm:text-sm">
                            Confirm Decline
                        </button>
                        <button type="button" id="cancel-decline-btn" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm dark:bg-gray-600 dark:text-gray-200 dark:border-gray-500 dark:hover:bg-gray-500">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>


    {{-- Scripts and Styles for SortableJS --}}
    <style>
        .route-step {
            position: relative; /* Needed for the delete button positioning */
            -webkit-user-select: none !important;
            -moz-user-select: none !important;
            -ms-user-select: none !important;
            user-select: none !important;
            margin-right: 1rem; /* Explicitly add gap */
        }
        /* Remove margin from the last step */
        .route-step:last-child {
            margin-right: 0;
        }
        .sortable-ghost {
            opacity: 0.4;
            background: #a5b4fc;
        }
        .sortable-chosen {
            cursor: grabbing;
        }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const routeList = document.getElementById('route-list');
            const routeForm = document.getElementById('route-form');
            const hiddenInput = document.getElementById('final_route');
            const addStepBtn = document.getElementById('add-step-btn');
            const departmentSelect = document.getElementById('department-select');

            const sortable = new window.Sortable(routeList, {
                animation: 150,
                ghostClass: 'sortable-ghost',
                chosenClass: 'sortable-chosen',
                onUpdate: function() {
                    updateStepNumbers();
                }
            });

            function updateStepNumbers() {
                const steps = routeList.querySelectorAll('.route-step');
                steps.forEach((step, index) => {
                    step.querySelector('.font-bold').textContent = index + 1;
                });
            }

            function createStepElement(departmentName) {
                const newStep = document.createElement('div');
                newStep.className = 'route-step flex-shrink-0 w-40 p-4 bg-white dark:bg-gray-700 rounded-lg shadow-md cursor-move text-center';
                newStep.innerHTML = `
                    <button type="button" class="delete-step-btn w-6 h-6 bg-red-500 text-white rounded-full flex items-center justify-center text-xl" style="position: absolute; top: -0.25rem; right: -0.25rem;">&times;</button>
                    <div class="font-bold text-lg text-indigo-600 dark:text-indigo-400"></div>
                    <div class="step-name text-sm mt-1">${departmentName}</div>
                `;
                return newStep;
            }

            addStepBtn.addEventListener('click', function() {
                const selectedDepartment = departmentSelect.value;
                if (!selectedDepartment || departmentSelect.selectedIndex === 0) {
                    return;
                }
                const newStep = createStepElement(selectedDepartment);
                routeList.appendChild(newStep);
                updateStepNumbers();
                departmentSelect.selectedIndex = 0;
            });

            // Event delegation for delete buttons
            routeList.addEventListener('click', function(e) {
                if (e.target && e.target.classList.contains('delete-step-btn')) {
                    e.target.closest('.route-step').remove();
                    updateStepNumbers();
                }
            });

            routeForm.addEventListener('submit', function (e) {
                const finalRouteOrder = Array.from(routeList.querySelectorAll('.step-name')).map(el => el.textContent.trim());
                hiddenInput.value = JSON.stringify(finalRouteOrder);
            });

            // Decline Modal Logic
            const declineModal = document.getElementById('decline-modal');
            const openDeclineModalBtn = document.getElementById('open-decline-modal-btn');
            const cancelDeclineBtn = document.getElementById('cancel-decline-btn');

            openDeclineModalBtn.addEventListener('click', () => {
                declineModal.classList.remove('hidden');
            });

            function hideDeclineModal() {
                declineModal.classList.add('hidden');
            }

            cancelDeclineBtn.addEventListener('click', hideDeclineModal);

            declineModal.addEventListener('click', (e) => {
                // Check if the click is on the background overlay
                if (e.target === declineModal) {
                    hideDeclineModal();
                }
            });
        });
    </script>
</x-app-layout>
