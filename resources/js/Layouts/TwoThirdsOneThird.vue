<template>
    <div class="flex flex-wrap md:flex-nowrap">
        <div class="w-full md:w-2/3">
            <slot name="main"></slot>
        </div>
        <div class="w-full md:w-1/3">
            <slot name="sidebar"></slot>
        </div>
    </div>
</template>