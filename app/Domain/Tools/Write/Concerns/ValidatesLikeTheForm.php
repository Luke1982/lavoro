<?php

namespace App\Domain\Tools\Write\Concerns;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator as ValidatorFactory;
use Illuminate\Validation\Validator;

/**
 * Checking a tool's input with the rules of the screen that makes the same record.
 *
 * A second set of rules beside the form's would be a second opinion on what a
 * valid customer is, and the two would drift: a postal code the form refuses
 * would go in through the assistant, or the other way round. So the tool asks
 * the form's own request, and answers with the form's own Dutch messages.
 */
trait ValidatesLikeTheForm
{
    /**
     * The form request is built around the data rather than instantiated empty,
     * because some rules read the request itself: a location code is unique per
     * customer, and the rule learns which customer by asking the request.
     *
     * Some read more than the data. A contract's edit form leaves the price out
     * for whoever may not see it and refuses a hand-set price on a formula
     * contract; a material says whether its quantity may have decimals. Those
     * rules ask the request for its user and its route, so they are given the
     * person and the records the route would have bound.
     *
     * The rest of what a form request does around its rules runs as it would
     * there, in the same order: prepareForValidation() first, which may rewrite
     * the data the rules then see, then withValidator() and the after() checks,
     * given the validator as the form gives it. Those are as much the form's
     * rules as rules() is: a break longer than the time worked is refused in
     * after(). Run inside the request, because the form calls them from there
     * and some are protected. A rule the tool cannot meet by its nature -- the
     * signature a chat cannot give -- is left out by name ($without_rules)
     * rather than the whole form copied without it.
     *
     * @param  class-string<FormRequest>  $request_class
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $route_parameters  Bound by name, as the route would: ['quote' => $quote].
     * @param  array<int, string>  $without_rules  Fields whose rules this caller cannot meet.
     */
    private function asTheFormWould(
        string $request_class,
        array $data,
        ?User $user = null,
        array $route_parameters = [],
        array $without_rules = [],
    ): Validator {
        $request = $this->formRequest($request_class, $data, $user, $route_parameters);

        return (function () use ($without_rules): Validator {
            $this->prepareForValidation();

            $validator = ValidatorFactory::make(
                $this->validationData(),
                Arr::except($this->rules(), $without_rules),
                $this->messages(),
                $this->attributes(),
            )->stopOnFirstFailure($this->stopOnFirstFailure);

            if (method_exists($this, 'withValidator')) {
                $this->withValidator($validator);
            }

            if (method_exists($this, 'after')) {
                $validator->after(app()->call($this->after(...), ['validator' => $validator]));
            }

            return $validator;
        })->call($request);
    }

    /**
     * Whether the form would let this person in: its own authorize(), given the
     * records its route would have bound. A copy of that check in the tool would
     * keep the old rule the day the form's changes.
     *
     * @param  class-string<FormRequest>  $request_class
     * @param  array<string, mixed>  $route_parameters  Bound by name, as the route would: ['serviceorder' => $order].
     */
    private function allowedAsTheFormWould(string $request_class, User $user, array $route_parameters = []): bool
    {
        $request = $this->formRequest($request_class, [], $user, $route_parameters);

        return (bool) (fn () => $this->authorize())->call($request);
    }

    /**
     * @param  class-string<FormRequest>  $request_class
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $route_parameters
     */
    private function formRequest(string $request_class, array $data, ?User $user, array $route_parameters): FormRequest
    {
        $request = $request_class::create('/', 'POST', $data);

        if ($user !== null) {
            $request->setUserResolver(fn () => $user);
        }

        if ($route_parameters !== []) {
            $route = (new Route('POST', '/', []))->bind($request);

            foreach ($route_parameters as $name => $value) {
                $route->setParameter($name, $value);
            }

            $request->setRouteResolver(fn () => $route);
        }

        return $request;
    }

    private function complaintOf(Validator $validator): string
    {
        return 'Zo kan het niet worden opgeslagen: ' . implode(' ', $validator->errors()->all());
    }
}
