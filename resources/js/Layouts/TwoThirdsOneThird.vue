<template>
    <div class="grid grid-cols-12 gap-x-6">
        <div class="col-span-12 md:col-span-8">
            <slot name="main"></slot>
        </div>
        <div class="col-span-12 md:col-span-4">
            <slot name="sidebar"></slot>
        </div>
    </div>
</template>