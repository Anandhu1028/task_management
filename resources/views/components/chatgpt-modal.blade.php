<div
    id="tfChatGptModal"
    class="tf-chatgpt-modal"
    role="dialog"
    aria-modal="true"
    aria-labelledby="tfChatGptModalTitle"
    hidden
>
    <div class="tf-chatgpt-modal__backdrop" data-tf-chatgpt-close tabindex="-1" aria-hidden="true"></div>
    <div class="tf-chatgpt-modal__panel" role="document">
        {{-- Header --}}
        <div class="tf-chatgpt-modal__header">
            <div class="tf-chatgpt-modal__brand">
                <span class="tf-chatgpt-modal__brand-icon" aria-hidden="true">
                    <svg width="20" height="20" viewBox="0 0 41 41" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M37.532 16.87a9.963 9.963 0 0 0-.856-8.184 10.078 10.078 0 0 0-10.855-4.835 9.964 9.964 0 0 0-6.284-3.676 10.079 10.079 0 0 0-10.63 4.931 9.961 9.961 0 0 0-6.663 4.834 10.08 10.08 0 0 0 1.24 11.817 9.965 9.965 0 0 0 .856 8.185 10.079 10.079 0 0 0 10.855 4.835 9.965 9.965 0 0 0 6.284 3.675 10.079 10.079 0 0 0 10.63-4.931 9.96 9.96 0 0 0 6.663-4.834 10.079 10.079 0 0 0-1.24-11.817zm-14.54 20.282a7.474 7.474 0 0 1-4.798-1.735c.061-.033.168-.091.237-.134l7.964-4.6a1.294 1.294 0 0 0 .655-1.134V19.054l3.366 1.944a.12.12 0 0 1 .066.092v9.299a7.505 7.505 0 0 1-7.49 7.763zm-16.134-6.874a7.47 7.47 0 0 1-.894-5.023c.06.036.162.099.237.141l7.964 4.6a1.297 1.297 0 0 0 1.308 0l9.724-5.614v3.888a.12.12 0 0 1-.048.103l-8.051 4.649a7.504 7.504 0 0 1-10.24-2.744zm-2.09-17.49a7.47 7.47 0 0 1 3.897-3.286c0 .068-.004.19-.004.274v9.201a1.294 1.294 0 0 0 .654 1.132l9.723 5.614-3.366 1.944a.12.12 0 0 1-.114.012L7.544 23.86a7.505 7.505 0 0 1-2.776-10.071zm27.658 6.437l-9.724-5.615 3.367-1.943a.121.121 0 0 1 .114-.012l8.048 4.648a7.498 7.498 0 0 1-1.158 13.528v-9.476a1.293 1.293 0 0 0-.647-1.13zm3.35-5.043c-.059-.037-.162-.099-.236-.141l-7.965-4.6a1.298 1.298 0 0 0-1.308 0l-9.723 5.614v-3.888a.12.12 0 0 1 .048-.103l8.05-4.645a7.497 7.497 0 0 1 11.135 7.763zm-21.063 6.929l-3.367-1.944a.12.12 0 0 1-.065-.092v-9.299a7.497 7.497 0 0 1 12.293-5.756 6.94 6.94 0 0 0-.236.134l-7.965 4.6a1.294 1.294 0 0 0-.654 1.132l-.006 11.225zm1.829-3.943l4.33-2.501 4.332 2.5v4.999l-4.331 2.5-4.331-2.5V18.188z" fill="currentColor"/>
                    </svg>
                </span>
                <div class="tf-chatgpt-modal__brand-meta">
                    <span class="tf-chatgpt-modal__brand-name">ChatGPT Assistant</span>
                    <span class="tf-chatgpt-modal__brand-badge">Official Integration</span>
                </div>
            </div>
            <div class="tf-chatgpt-modal__header-actions">
                <a
                    href="https://chatgpt.com/"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="tf-chatgpt-modal__ext-link"
                    title="Open in new window"
                    aria-label="Open ChatGPT in new tab"
                >
                    <i class="bi bi-box-arrow-up-right"></i>
                </a>
                <button
                    class="tf-chatgpt-modal__close-btn"
                    type="button"
                    id="tfChatGptModalClose"
                    aria-label="Close dialog"
                    data-tf-chatgpt-close
                >
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
        </div>

        {{-- Body --}}
        <div class="tf-chatgpt-modal__body">
            <div class="tf-chatgpt-modal__hero">
                <div class="tf-chatgpt-modal__hero-icon" aria-hidden="true">
                    <svg width="44" height="44" viewBox="0 0 41 41" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M37.532 16.87a9.963 9.963 0 0 0-.856-8.184 10.078 10.078 0 0 0-10.855-4.835 9.964 9.964 0 0 0-6.284-3.676 10.079 10.079 0 0 0-10.63 4.931 9.961 9.961 0 0 0-6.663 4.834 10.08 10.08 0 0 0 1.24 11.817 9.965 9.965 0 0 0 .856 8.185 10.079 10.079 0 0 0 10.855 4.835 9.965 9.965 0 0 0 6.284 3.675 10.079 10.079 0 0 0 10.63-4.931 9.96 9.96 0 0 0 6.663-4.834 10.079 10.079 0 0 0-1.24-11.817zm-14.54 20.282a7.474 7.474 0 0 1-4.798-1.735c.061-.033.168-.091.237-.134l7.964-4.6a1.294 1.294 0 0 0 .655-1.134V19.054l3.366 1.944a.12.12 0 0 1 .066.092v9.299a7.505 7.505 0 0 1-7.49 7.763zm-16.134-6.874a7.47 7.47 0 0 1-.894-5.023c.06.036.162.099.237.141l7.964 4.6a1.297 1.297 0 0 0 1.308 0l9.724-5.614v3.888a.12.12 0 0 1-.048.103l-8.051 4.649a7.504 7.504 0 0 1-10.24-2.744zm-2.09-17.49a7.47 7.47 0 0 1 3.897-3.286c0 .068-.004.19-.004.274v9.201a1.294 1.294 0 0 0 .654 1.132l9.723 5.614-3.366 1.944a.12.12 0 0 1-.114.012L7.544 23.86a7.505 7.505 0 0 1-2.776-10.071zm27.658 6.437l-9.724-5.615 3.367-1.943a.121.121 0 0 1 .114-.012l8.048 4.648a7.498 7.498 0 0 1-1.158 13.528v-9.476a1.293 1.293 0 0 0-.647-1.13zm3.35-5.043c-.059-.037-.162-.099-.236-.141l-7.965-4.6a1.298 1.298 0 0 0-1.308 0l-9.723 5.614v-3.888a.12.12 0 0 1 .048-.103l8.05-4.645a7.497 7.497 0 0 1 11.135 7.763zm-21.063 6.929l-3.367-1.944a.12.12 0 0 1-.065-.092v-9.299a7.497 7.497 0 0 1 12.293-5.756 6.94 6.94 0 0 0-.236.134l-7.965 4.6a1.294 1.294 0 0 0-.654 1.132l-.006 11.225zm1.829-3.943l4.33-2.501 4.332 2.5v4.999l-4.331 2.5-4.331-2.5V18.188z" fill="currentColor"/>
                    </svg>
                </div>
                <h2 id="tfChatGptModalTitle" class="tf-chatgpt-modal__title">TaskFlow on ChatGPT</h2>
                <p class="tf-chatgpt-modal__desc">
                    Connect directly with the TaskFlow Assistant on ChatGPT to list your tasks, query analytics, and generate real-time productivity summaries through secure MCP.
                </p>
            </div>

            {{-- Example Prompt Pills --}}
            <div class="tf-chatgpt-modal__prompts">
                <span class="tf-chatgpt-modal__prompts-label">Try asking:</span>
                <div class="tf-chatgpt-modal__prompts-list">
                    <span class="tf-chatgpt-prompt-tag">
                        <i class="bi bi-chat-quote"></i> "List all tasks assigned to me"
                    </span>
                    <span class="tf-chatgpt-prompt-tag">
                        <i class="bi bi-chat-quote"></i> "Show high priority tasks pending"
                    </span>
                    <span class="tf-chatgpt-prompt-tag">
                        <i class="bi bi-chat-quote"></i> "Summarize team performance"
                    </span>
                </div>
            </div>

            {{-- CTA action --}}
            <div class="tf-chatgpt-modal__actions">
                <a
                    href="https://chatgpt.com/"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="tf-chatgpt-modal__btn-launch"
                    id="tfChatGptLaunchBtn"
                >
                    <span class="tf-chatgpt-modal__btn-icon" aria-hidden="true">
                        <svg width="18" height="18" viewBox="0 0 41 41" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M37.532 16.87a9.963 9.963 0 0 0-.856-8.184 10.078 10.078 0 0 0-10.855-4.835 9.964 9.964 0 0 0-6.284-3.676 10.079 10.079 0 0 0-10.63 4.931 9.961 9.961 0 0 0-6.663 4.834 10.08 10.08 0 0 0 1.24 11.817 9.965 9.965 0 0 0 .856 8.185 10.079 10.079 0 0 0 10.855 4.835 9.965 9.965 0 0 0 6.284 3.675 10.079 10.079 0 0 0 10.63-4.931 9.96 9.96 0 0 0 6.663-4.834 10.079 10.079 0 0 0-1.24-11.817zm-14.54 20.282a7.474 7.474 0 0 1-4.798-1.735c.061-.033.168-.091.237-.134l7.964-4.6a1.294 1.294 0 0 0 .655-1.134V19.054l3.366 1.944a.12.12 0 0 1 .066.092v9.299a7.505 7.505 0 0 1-7.49 7.763zm-16.134-6.874a7.47 7.47 0 0 1-.894-5.023c.06.036.162.099.237.141l7.964 4.6a1.297 1.297 0 0 0 1.308 0l9.724-5.614v3.888a.12.12 0 0 1-.048.103l-8.051 4.649a7.504 7.504 0 0 1-10.24-2.744zm-2.09-17.49a7.47 7.47 0 0 1 3.897-3.286c0 .068-.004.19-.004.274v9.201a1.294 1.294 0 0 0 .654 1.132l9.723 5.614-3.366 1.944a.12.12 0 0 1-.114.012L7.544 23.86a7.505 7.505 0 0 1-2.776-10.071zm27.658 6.437l-9.724-5.615 3.367-1.943a.121.121 0 0 1 .114-.012l8.048 4.648a7.498 7.498 0 0 1-1.158 13.528v-9.476a1.293 1.293 0 0 0-.647-1.13zm3.35-5.043c-.059-.037-.162-.099-.236-.141l-7.965-4.6a1.298 1.298 0 0 0-1.308 0l-9.723 5.614v-3.888a.12.12 0 0 1 .048-.103l8.05-4.645a7.497 7.497 0 0 1 11.135 7.763zm-21.063 6.929l-3.367-1.944a.12.12 0 0 1-.065-.092v-9.299a7.497 7.497 0 0 1 12.293-5.756 6.94 6.94 0 0 0-.236.134l-7.965 4.6a1.294 1.294 0 0 0-.654 1.132l-.006 11.225zm1.829-3.943l4.33-2.501 4.332 2.5v4.999l-4.331 2.5-4.331-2.5V18.188z" fill="currentColor"/>
                        </svg>
                    </span>
                    <span>Open ChatGPT</span>
                    <i class="bi bi-box-arrow-up-right tf-chatgpt-modal__btn-arrow"></i>
                </a>
            </div>

            <p class="tf-chatgpt-modal__footer-note">
                <i class="bi bi-shield-check"></i>
                Opens official ChatGPT in a new tab. Your TaskFlow session remains intact.
            </p>
        </div>
    </div>
</div>
