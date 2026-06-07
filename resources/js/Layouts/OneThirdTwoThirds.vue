<template>
    <div class="grid grid-cols-12 gap-x-6">
        <div class="col-span-12 md:col-span-4">
            <slot name="narrow"></slot>
        </div>
        <div class="col-span-12 md:col-span-8 mt-5 md:mt-0">
            <slot name="wide"></slot>
        </div>
    </div>
</template>
