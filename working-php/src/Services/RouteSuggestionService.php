<?php

namespace App\Services;

/**
 * Suggests routes for the intake form and decides when a finalized route should be learned.
 * Non-official purposes (e.g. "Others") are never suggested and never learned.
 */
class RouteSuggestionService
{
    /**
     * Build the suggested route shown at intake.
     *
     * @param array{is_official:int|bool, suggested_route:?string} $purpose
     * @return list<array{name:string, is_injected:bool}>
     */
    public function suggest(array $purpose, ?string $guestDepartment): array
    {
        // Non-official purposes get no suggestion
        if (empty($purpose['is_official'])) {
            return [];
        }

        // Normalize the stored route to a list of names, then wrap each as a step
        $names = $this->storedNames($purpose['suggested_route'] ?? null);
        $steps = array_map(fn($name) => ['name' => $name, 'is_injected' => false], $names);

        // Inject the guest's unit, or only flag it when it is already first
        if ($guestDepartment !== null && $guestDepartment !== '') {
            if (!empty($steps) && $steps[0]['name'] === $guestDepartment) {
                $steps[0]['is_injected'] = true;
            } else {
                array_unshift($steps, ['name' => $guestDepartment, 'is_injected' => true]);
            }
        }

        return $steps;
    }

    /**
     * Decide which route (if any) to store on the purpose after a finalize.
     *
     * @param list<string> $routeNames
     * @return list<string>|null null means "do not write"
     */
    public function learnedRoute(array $purpose, array $routeNames, ?string $guestDepartment): ?array
    {
        // Non-official purposes are never learned
        if (empty($purpose['is_official'])) {
            return null;
        }

        // Do not permanently learn a specific guest's unit
        $learned = array_values($routeNames);
        if ($guestDepartment !== null && $guestDepartment !== '' && !empty($learned) && $learned[0] === $guestDepartment) {
            array_shift($learned);
        }

        // Compare semantically, so MySQL's canonical JSON spacing does not matter
        if ($learned === $this->storedNames($purpose['suggested_route'] ?? null)) {
            return null;
        }

        return $learned;
    }

    /**
     * Decode a stored route JSON into a list of names (accepts strings and {name: ...} objects).
     *
     * @return list<string>
     */
    private function storedNames(?string $json): array
    {
        // Empty or invalid JSON means no stored route
        $decoded = $json ? json_decode($json, true) : null;
        if (!is_array($decoded)) {
            return [];
        }

        // Reduce every entry to its name
        $names = [];
        foreach ($decoded as $entry) {
            $name = is_array($entry) ? ($entry['name'] ?? '') : $entry;
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }
}
