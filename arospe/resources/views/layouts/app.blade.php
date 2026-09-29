<x-layouts::app.sidebar :title="$title ?? null" :heading="$heading ?? null" :subheading="$subheading ?? null">
    <flux:main>
        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
