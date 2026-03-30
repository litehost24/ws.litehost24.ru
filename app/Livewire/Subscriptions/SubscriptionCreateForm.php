<?php

namespace App\Livewire\Subscriptions;

use App\Models\Subscription;
use Illuminate\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;

class SubscriptionCreateForm extends Component
{
    #[Validate('required')]
    public $name = '';

    public $description = '';

    #[Validate('required')]
    public $price = '';

    public $is_hidden = true;

    public function create()
    {
        $this->validate();

        Subscription::create(
            [
                'name' => $this->name,
                'description' => $this->description,
                'price' => $this->price * 100, // Convert to kopecks
                'is_hidden' => $this->is_hidden,
            ]);
        session()->flash('success', 'Подписка успешно создана');

        return $this->redirect(route('subscriptions'));
    }

    public function render(): View
    {
        return view('subscriptions.subscription-create-form');
    }
}
