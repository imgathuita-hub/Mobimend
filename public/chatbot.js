(() => {
  const toggleBtn = document.querySelector(".chatbot-toggle");
  const panel = document.querySelector(".chatbot-panel");
  const messages = document.querySelector(".chatbot-messages");
  const input = document.querySelector(".chatbot-input");
  const sendBtn = document.querySelector(".chatbot-send");

  if (!toggleBtn || !panel || !messages || !input || !sendBtn) {
    return;
  }

  const rules = [
    {
      keywords: ["hello", "hi", "hey", "good morning", "good afternoon", "good evening"],
      response: "Hi! I can help with repairs, prices, parts, and bookings. What do you need help with?",
    },
    {
      keywords: ["price", "cost", "how much", "pricing", "quote"],
      response:
        "Pricing depends on the device model and issue. Tell me your phone model and what is wrong, and I will estimate.",
    },
    {
      keywords: ["screen", "cracked", "display", "touch", "lcd", "glass"],
      response:
        "We replace cracked screens and LCDs for most models. Share your phone model for a quick estimate.",
    },
    {
      keywords: ["battery", "drain", "power", "charging", "won't charge"],
      response:
        "Battery and charging issues are common fixes. Tell me the model and symptoms so I can advise.",
    },
    {
      keywords: ["water", "liquid", "wet", "spill"],
      response:
        "For water damage, stop using the phone and bring it in quickly. We can diagnose and clean the board.",
    },
    {
      keywords: ["time", "how long", "same day", "duration", "repair time"],
      response:
        "Most repairs are same-day. Screen and battery replacements are often 1-2 hours if parts are in stock.",
    },
    {
      keywords: ["warranty", "guarantee"],
      response:
        "Warranty covers the repaired parts only. It does not apply to physical damage, liquid damage, power surges, or mishandling.",
    },
    {
      keywords: ["location", "address", "where", "directions"],
      response:
        "We are located at Juja Mum and Dad Business Center, stall 9E. Want directions or nearby landmarks?",
    },
    {
      keywords: ["hours", "open", "close", "time open", "opening hours"],
      response: "We are open daily from 8 AM to 10 PM. Need help booking a repair?",
    },
    {
      keywords: ["book", "booking", "appointment", "schedule"],
      response:
        "You can book a repair from the site. Tell me your model and issue, and I will guide you.",
    },
    {
      keywords: ["parts", "spares", "accessories", "cases", "chargers"],
      response:
        "We stock accessories and spare parts for all phone brands. Tell me the model and part you need.",
    },
    {
      keywords: ["brand", "brands", "iphone", "samsung", "tecno", "infinix", "oppo", "xiaomi", "huawei"],
      response:
        "We service all phone brands. Tell me your model and the issue, and I will guide you.",
    },
    {
      keywords: ["average price", "average cost", "range", "price range", "budget"],
      response:
        "Typical repairs range from 2,000 to 7,500 on average, depending on model and issue.",
    },
    {
      keywords: ["phone", "contact", "call", "number"],
      response:
        "You can call us at 0799 183907 or ask me to help you book a repair.",
    },
    {
      keywords: ["email", "mail"],
      response:
        "You can reach us at mobimendspares@gmail.com. Want help with a repair booking?",
    },
  ];

  const addMessage = (text, role) => {
    const message = document.createElement("div");
    message.className = `chatbot-message ${role}`;
    message.textContent = text;
    messages.appendChild(message);
    messages.scrollTop = messages.scrollHeight;
  };

  const findResponse = (text) => {
    const lower = text.toLowerCase();
    for (const rule of rules) {
      if (rule.keywords.some((keyword) => lower.includes(keyword))) {
        return rule.response;
      }
    }
    return "I can help with repairs, prices, parts, and bookings. Tell me your phone model and issue.";
  };

  const handleSend = () => {
    const text = input.value.trim();
    if (!text) return;
    addMessage(text, "user");
    input.value = "";

    const reply = findResponse(text);
    setTimeout(() => addMessage(reply, "bot"), 300);
  };

  toggleBtn.addEventListener("click", () => {
    const isOpen = panel.classList.toggle("open");
    toggleBtn.setAttribute("aria-expanded", String(isOpen));
    panel.setAttribute("aria-hidden", String(!isOpen));
  });

  sendBtn.addEventListener("click", handleSend);
  input.addEventListener("keydown", (event) => {
    if (event.key === "Enter") {
      event.preventDefault();
      handleSend();
    }
  });

  addMessage(
    "Hi! Ask me about repairs, pricing, parts, or booking a repair.",
    "bot"
  );
})();
