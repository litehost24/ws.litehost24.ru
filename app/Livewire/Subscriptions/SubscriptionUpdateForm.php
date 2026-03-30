<?php

namespace App\Livewire\Subscriptions;

use App\Models\Subscription;
use Illuminate\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;

class SubscriptionUpdateForm extends Component
{
    public $id = null;

    #[Validate('required')]
    public $name = '';

    public $description = '';

    #[Validate('required')]
    public $price = '';

    public $is_hidden = false;

    public function update()
    {
        $this->validate();

        Subscription::where('id', $this->id)->update(
            [
                'name' => $this->name,
                'description' => $this->description,
                'price' => $this->price * 100, // Convert to kopecks
                'is_hidden' => $this->is_hidden,
            ]);
        session()->flash('success', 'Подписка успешно Обновлена');

        return $this->redirect(route('subscriptions'));
    }

    public function delete()
    {
        Subscription::where('id', $this->id)->delete();
        session()->flash('success', 'Подписка успешно удалена');
        return $this->redirect(route('subscriptions'));
    }

    public function render(): View
    {
        $this->price = $this->price / 100; // Convert to rubles for display
        return view('subscriptions.subscription-update-form');
    }
}
